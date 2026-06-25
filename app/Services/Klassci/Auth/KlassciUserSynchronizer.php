<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Exceptions\KlassciAccountConflictException;
use App\Models\Institution;
use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
use App\Services\MatiereSyncService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Synchronise un utilisateur LMS local depuis les données KLASSCI.
 *
 * ## Issue #120 — Extrait verbatim de `AuthController::syncUserFromKlassci()`
 *
 * Logique extraite des lignes 388-465 de l'ancien `AuthController.php`. Aucun
 * changement comportemental — uniquement déplacement dans un collaborateur DIP.
 *
 * ## Invariants sécurité préservés (CRITICAL)
 *
 * ### CRITICAL-05 — séparation `role` / `klassci_role` (PR #118)
 *
 * Pour un user **existant** : `role` LMS reste **figé** (jamais écrasé par
 * KLASSCI). Seul `klassci_role` capture la valeur de KLASSCI. C'est la défense
 * contre l'escalade de privilèges via un KLASSCI compromis qui pousserait
 * `role: 'supradmin'`. Cf. `.claude/specs/critical-05-klassci-role-separation/`
 * §4 (REQ-3).
 *
 * Pour un user **nouveau** : `role` est initialisé à la valeur KLASSCI — seul
 * chemin où KLASSCI peut initialiser l'autorisation LMS d'un user découvert.
 *
 * ### Issue #119 — `klassci_enseignant_id` write-once (PR #122)
 *
 * Pour un user **nouveau** : initialisé depuis `klassci_data['enseignant_id']`
 * UNE SEULE FOIS à la création.
 *
 * Pour un user **existant** : `klassci_enseignant_id` n'est **JAMAIS** réécrit
 * (ni au login ni au re-sync passif 24h). Source d'autorité unique pour
 * l'ownership des évaluations. Cf. `.claude/specs/klassci-enseignant-id-separation/`.
 *
 * ### Issue #75 — isolation cross-tenant (PR #136)
 *
 * Recherche par `(klassci_id, institution_id)` — pas de lookup par URL.
 * Fallback par email scopé à `institution_id` (jamais cross-institution).
 *
 * ## DI strict
 *
 * - `ConnectionInterface` (DB) pour `transaction()` — pas de Facade `DB::`
 * - `Hasher` (contract) pour `Hash::make()` — pas de Facade `Hash::`
 * - `KlassciProxyService` pour `me/dashboard` (sync classes étudiant)
 * - `MatiereSyncService` pour la sync des matières (enseignant/coordinateur) — #258
 * - `LoggerInterface` (PSR-3) pour les logs
 *
 * @see app/Http/Controllers/API/AuthController.php (avant refactor) lignes 388-528
 * @see .claude/specs/auth-controller-refactor/design.md §2.4
 */
class KlassciUserSynchronizer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Hasher $hasher,
        private readonly KlassciProxyService $klassciService,
        private readonly MatiereSyncService $matiereSync,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crée ou met à jour le user LMS local depuis les données KLASSCI.
     *
     * @param  array<string, mixed>  $klassciUser  Payload `data.user` de `/auth/login`
     * @param  string  $klassciToken               Token Bearer KLASSCI à stocker
     * @param  string  $tenantUrl                  URL du tenant KLASSCI
     * @param  Institution|null  $institution      Institution résolue depuis le slug du tenant
     */
    public function sync(
        array $klassciUser,
        string $klassciToken,
        string $tenantUrl,
        ?Institution $institution,
    ): User {
        $institutionId = $institution?->id;

        /** @var User $synced */
        $synced = $this->db->transaction(
            fn (): User => $this->doSync($klassciUser, $klassciToken, $tenantUrl, $institutionId)
        );

        return $synced;
    }

    /**
     * Orchestrateur de la transaction sync (≤ 40 lignes — §5).
     *
     * @param  array<string, mixed>  $klassciUser
     */
    private function doSync(array $klassciUser, string $klassciToken, string $tenantUrl, ?int $institutionId): User
    {
        $klassciId = $klassciUser['id'];
        $email     = $klassciUser['email'];

        $user = $this->findExistingUser($klassciId, $email, $institutionId);

        // Détection serveur AVANT écriture : l'email cible est-il déjà détenu par
        // un AUTRE compte de l'institution ? Email = un compte à vie côté KLASSCI,
        // donc ce conflit est toujours une anomalie de données. On échoue proprement
        // (KlassciAccountConflictException → 409) plutôt que de laisser la contrainte
        // unique remonter en 500, ou d'écraser silencieusement l'autre compte.
        $this->assertEmailNotOwnedByAnotherAccount(
            is_string($email) ? $email : '',
            $institutionId,
            $user?->id,
        );

        $commonData = $this->buildCommonData($klassciUser, $klassciToken, $tenantUrl, $institutionId);

        if ($user !== null) {
            // SÉCURITÉ — REQ-3 spec critical-05 : pour un user existant, `role` LMS
            // reste figé. Seul `klassci_role` capture la valeur de KLASSCI.
            $user->update($commonData);
        } else {
            $user = $this->createNewUser($klassciUser, $commonData, $institutionId, $tenantUrl);
        }

        if ($user->isStudent()) {
            $this->syncStudentClasses($user, $klassciToken);
        } elseif ($institutionId !== null && ($user->isTeacher() || $user->isCoordinator())) {
            // #258 — peupler les matières locales (validations exists:matieres,id +
            // affichage du libellé). Au login le tenant n'est pas résolu, on passe
            // donc l'institution_id explicitement.
            $this->syncTeacherMatieres($klassciToken, $institutionId);
        }

        return $user;
    }

    /**
     * Synchronise les matières de l'enseignant/coordinateur au login.
     *
     * Calqué sur {@see syncStudentClasses()} : un échec de la sync matières ne
     * doit JAMAIS interrompre le login (try/catch, log, pas de rethrow).
     */
    private function syncTeacherMatieres(string $klassciToken, int $institutionId): void
    {
        try {
            $this->matiereSync->syncUserMatieres($klassciToken, $institutionId);
        } catch (\Throwable $e) {
            $this->logger->error('Erreur sync matières au login', [
                'institution_id' => $institutionId,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Recherche un user existant par (klassci_id, institution_id),
     * avec fallback email scopé.
     */
    /**
     * Garde-fou : refuse d'assigner un email déjà détenu par un autre compte de
     * la même institution. `$currentUserId` est l'id du compte en cours de sync
     * (exclu du contrôle), null lors d'une création.
     *
     * @throws KlassciAccountConflictException si un autre compte détient l'email.
     */
    private function assertEmailNotOwnedByAnotherAccount(string $email, ?int $institutionId, ?int $currentUserId): void
    {
        if ($email === '') {
            return;
        }

        $query = User::withoutGlobalScope('institution')
            ->where('email', $email)
            ->where('institution_id', $institutionId);

        if ($currentUserId !== null) {
            $query->where('id', '!=', $currentUserId);
        }

        $conflict = $query->first();
        if ($conflict === null) {
            return;
        }

        // Anomalie de données : tracée pour réconciliation admin. On hash l'email
        // (PII) et on garde les klassci_id pour corréler sans exposer le clair.
        $this->logger->error('Conflit email au sync KLASSCI — compte non synchronisé', [
            'email_hash'               => substr(hash('sha256', $email), 0, 8),
            'institution_id'           => $institutionId,
            'conflicting_user_id'      => $conflict->id,
            'conflicting_klassci_id'   => $conflict->klassci_id,
            'incoming_user_id'         => $currentUserId,
        ]);

        throw new KlassciAccountConflictException(
            'Conflit de compte : cet email est déjà associé à un autre compte de l\'institution.'
        );
    }

    private function findExistingUser(mixed $klassciId, mixed $email, ?int $institutionId): ?User
    {
        $user = User::withoutGlobalScope('institution')
            ->where('klassci_id', $klassciId)
            ->where('institution_id', $institutionId)
            ->first();

        if ($user !== null) {
            return $user;
        }

        // Fallback par email scopé à l'institution (issue #75 préservé)
        return User::withoutGlobalScope('institution')
            ->where('email', $email)
            ->where('institution_id', $institutionId)
            ->first();
    }

    /**
     * Construit le tableau des champs propagés sur CHAQUE login (CREATE + UPDATE).
     *
     * Email syncé au login car l'utilisateur engage activement sa session
     * KLASSCI à cet instant — asymétrie vs re-sync passif documentée dans
     * `.claude/specs/critical-05-klassci-role-separation/design.md` §4.
     *
     * @param  array<string, mixed>  $klassciUser
     * @return array<string, mixed>
     */
    private function buildCommonData(array $klassciUser, string $klassciToken, string $tenantUrl, ?int $institutionId): array
    {
        return [
            'klassci_id'         => $klassciUser['id'],
            'name'               => $klassciUser['nom'] ?? $klassciUser['name'] ?? 'User',
            'email'              => $klassciUser['email'],
            'klassci_role'       => $klassciUser['role'] ?? 'etudiant',
            'klassci_token'      => $klassciToken,
            'klassci_tenant_url' => $tenantUrl,
            'klassci_data'       => json_encode(array_merge($klassciUser, ['_lms_tenant_url' => $tenantUrl])),
            'last_klassci_sync'  => now(),
            'institution_id'     => $institutionId,
        ];
    }

    /**
     * Initialise un nouvel utilisateur.
     *
     * SÉCURITÉ :
     *   • `role` LMS — REQ-3 spec critical-05 : seul chemin où KLASSCI peut
     *                  initialiser l'autorisation LMS d'un user découvert.
     *   • `klassci_enseignant_id` — REQ-3 spec klassci-enseignant-id-separation :
     *                  initialisation write-once. Plus jamais réécrit
     *                  (issue #119, autorité ownership évaluations).
     *
     * @param  array<string, mixed>  $klassciUser
     * @param  array<string, mixed>  $commonData
     */
    private function createNewUser(array $klassciUser, array $commonData, ?int $institutionId, string $tenantUrl): User
    {
        $klassciRole = $klassciUser['role'] ?? 'etudiant';

        $user = User::withoutGlobalScope('institution')->create(array_merge($commonData, [
            'role'                  => $klassciRole,
            'klassci_enseignant_id' => isset($klassciUser['enseignant_id']) && is_numeric($klassciUser['enseignant_id'])
                ? (int) $klassciUser['enseignant_id']
                : null,
            'password' => $this->hasher->make(uniqid()),
        ]));

        $this->logger->info('Nouvel utilisateur créé depuis KLASSCI', [
            'user_id'        => $user->id,
            'email'          => $klassciUser['email'],
            'klassci_id'     => $klassciUser['id'],
            'institution_id' => $institutionId,
            'tenant'         => $tenantUrl,
        ]);

        return $user;
    }

    /**
     * Synchronise la classe KLASSCI de l'étudiant dans la DB locale.
     *
     * Logique extraite verbatim de l'ancienne `AuthController::syncStudentClasses()`.
     */
    private function syncStudentClasses(User $user, string $klassciToken): void
    {
        try {
            $this->logger->info('Synchronisation des classes pour étudiant', [
                'user_id' => $user->id,
                'email'   => $user->email,
            ]);

            $classe = $this->extractClasseFromDashboard($user, $klassciToken);
            if ($classe === null) {
                return;
            }

            $this->persistStudentClasse($user, $classe);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la synchronisation des classes', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Extrait la classe de l'étudiant depuis `me/dashboard` KLASSCI.
     *
     * IMPORTANT: L'API retourne `classe` (singulier), pas `classes` (pluriel) —
     * un étudiant n'a qu'une seule classe.
     *
     * @return array<string, mixed>|null
     */
    private function extractClasseFromDashboard(User $user, string $klassciToken): ?array
    {
        $dashboard = $this->klassciService->requestWithUserToken($klassciToken, 'me/dashboard', 'GET');

        $data = $dashboard['data'] ?? [];
        $classe = is_array($data) && isset($data['classe']) && is_array($data['classe'])
            ? $data['classe']
            : null;

        if ($classe === null) {
            $this->logger->warning("Aucune classe trouvée pour l'étudiant", ['user_id' => $user->id]);

            return null;
        }

        $this->logger->info('Classe KLASSCI récupérée', [
            'user_id'    => $user->id,
            'classe_id'  => $classe['id'] ?? null,
            'classe_nom' => $classe['name'] ?? $classe['libelle'] ?? 'N/A',
        ]);

        return $classe;
    }

    /**
     * Persiste la classe étudiant : DELETE anciennes + CREATE nouvelle.
     *
     * @param  array<string, mixed>  $classe
     */
    private function persistStudentClasse(User $user, array $classe): void
    {
        $user->klassciClasses()->delete();

        $classeId = $classe['id'] ?? null;
        $classeNom = $classe['name'] ?? $classe['libelle'] ?? null;

        UserClass::create([
            'user_id'           => $user->id,
            'klassci_classe_id' => $classeId,
            'classe_nom'        => $classeNom,
            'classe_libelle'    => $classe['libelle'] ?? $classe['name'] ?? null,
            'classe_data'       => $classe,
            'synced_at'         => now(),
        ]);

        $this->logger->info('Classe synchronisée avec succès', [
            'user_id'   => $user->id,
            'classe_id' => $classeId,
        ]);
    }
}
