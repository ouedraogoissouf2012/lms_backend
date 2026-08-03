<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Data\KlassciDataWhitelist;
use App\Services\MatiereSyncService;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\ConnectionInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrateur : crée ou met à jour le user LMS local depuis les données KLASSCI.
 *
 * ## Issue #120 / #275 — orchestrateur fin (§1.1 ≤300, §5)
 *
 * Délègue à des collaborateurs DIP à responsabilité unique :
 *   - {@see KlassciEmailConflictGuard}        — garde email avant écriture (#253)
 *   - {@see KlassciEnseignantIdResolver}      — `klassci_enseignant_id` (#119/#267)
 *   - {@see StudentClassSynchronizer}         — classe étudiant via `me/dashboard`
 *   - {@see \App\Services\MatiereSyncService} — matières enseignant/coordinateur (#258)
 *
 * ## Invariants sécurité préservés
 *
 *   - CRITICAL-05 (#118) : pour un user **existant**, `role` LMS reste **figé**
 *     (jamais écrasé par KLASSCI) ; seul `klassci_role` capture la valeur KLASSCI.
 *     Défense contre l'escalade via un KLASSCI compromis. Pour un user **nouveau**,
 *     `role` est initialisé à la valeur KLASSCI (seul chemin d'init de l'autorisation).
 *   - #75 : recherche par `(klassci_id, institution_id)`, fallback email scopé
 *     à l'institution (jamais cross-institution).
 *
 * ## DI strict
 *
 *   - `ConnectionInterface` pour `transaction()` — pas de Facade `DB::`
 *   - `Hasher` (contract) pour `make()` — pas de Facade `Hash::`
 *
 * @see app/Http/Controllers/API/AuthController.php
 * @see .claude/specs/auth-controller-refactor/design.md §2.4
 */
class KlassciUserSynchronizer
{
    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly Hasher $hasher,
        private readonly MatiereSyncService $matiereSync,
        private readonly StudentClassSynchronizer $studentClassSync,
        private readonly KlassciEnseignantIdResolver $enseignantIdResolver,
        private readonly KlassciEmailConflictGuard $emailGuard,
        private readonly LoggerInterface $logger,
        private readonly KlassciDataWhitelist $whitelist,
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
     * Orchestrateur de la transaction sync.
     *
     * @param  array<string, mixed>  $klassciUser
     */
    private function doSync(array $klassciUser, string $klassciToken, string $tenantUrl, ?int $institutionId): User
    {
        $klassciId = $klassciUser['id'];
        $email     = $klassciUser['email'];

        $user = $this->findExistingUser($klassciId, $email, $institutionId);

        // Détection serveur AVANT écriture : email déjà détenu par un autre compte
        // de l'institution ? (#253 — anomalie → 409, pas 500, pas d'écrasement).
        $this->emailGuard->assertNotOwnedByAnother(
            is_string($email) ? $email : '',
            $institutionId,
            $user?->id,
        );

        $commonData = $this->buildCommonData($klassciUser, $klassciToken, $tenantUrl, $institutionId);

        if ($user !== null) {
            // SÉCURITÉ CRITICAL-05 : pour un user existant, `role` LMS reste figé.
            $user->update($commonData);
            $this->enseignantIdResolver->healIfNull($user, $klassciUser);
        } else {
            $user = $this->createNewUser($klassciUser, $commonData, $institutionId, $tenantUrl);
        }

        if ($user->isStudent()) {
            $this->studentClassSync->sync($user, $klassciToken);
        } elseif ($institutionId !== null && ($user->isTeacher() || $user->isCoordinator())) {
            // #258 — peupler les matières locales (validations exists:matieres,id +
            // affichage du libellé). Tenant non résolu au login → institution_id explicite.
            $this->syncTeacherMatieres($klassciToken, $institutionId);
        }

        return $user;
    }

    /**
     * Synchronise les matières de l'enseignant/coordinateur au login.
     *
     * Un échec de la sync matières ne doit JAMAIS interrompre le login
     * (try/catch, log, pas de rethrow).
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
     * Recherche un user existant par (klassci_id, institution_id), avec fallback
     * email scopé à l'institution (#75 préservé).
     */
    private function findExistingUser(mixed $klassciId, mixed $email, ?int $institutionId): ?User
    {
        $user = User::withoutGlobalScope('institution')
            ->where('klassci_id', $klassciId)
            ->where('institution_id', $institutionId)
            ->first();

        if ($user !== null) {
            return $user;
        }

        return User::withoutGlobalScope('institution')
            ->where('email', $email)
            ->where('institution_id', $institutionId)
            ->first();
    }

    /**
     * Construit le tableau des champs propagés sur CHAQUE login (CREATE + UPDATE).
     *
     * Email syncé au login car l'utilisateur engage activement sa session KLASSCI
     * à cet instant — asymétrie vs re-sync passif documentée dans
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
            // #477 : filtré par whitelist ; _lms_tenant_url (interne LMS) passé en
            // existant pour être préservé. Le cast KlassciData sérialise l'array.
            'klassci_data'       => $this->whitelist->filter($klassciUser, ['_lms_tenant_url' => $tenantUrl]),
            'last_klassci_sync'  => now(),
            'institution_id'     => $institutionId,
        ];
    }

    /**
     * Initialise un nouvel utilisateur.
     *
     * SÉCURITÉ :
     *   • `role` LMS — CRITICAL-05 : seul chemin où KLASSCI peut initialiser
     *     l'autorisation LMS d'un user découvert.
     *   • `klassci_enseignant_id` — résolu via {@see KlassciEnseignantIdResolver}
     *     (#119/#267), initialisé write-once à la création.
     *
     * @param  array<string, mixed>  $klassciUser
     * @param  array<string, mixed>  $commonData
     */
    private function createNewUser(array $klassciUser, array $commonData, ?int $institutionId, string $tenantUrl): User
    {
        $klassciRole = $klassciUser['role'] ?? 'etudiant';

        $user = User::withoutGlobalScope('institution')->create(array_merge($commonData, [
            'role'                  => $klassciRole,
            'klassci_enseignant_id' => $this->enseignantIdResolver->resolve($klassciUser),
            'password'              => $this->hasher->make(uniqid()),
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
}
