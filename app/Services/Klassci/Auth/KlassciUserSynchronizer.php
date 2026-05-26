<?php

declare(strict_types=1);

namespace App\Services\Klassci\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Models\UserClass;
use App\Services\KlassciProxyService;
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
        $klassciId     = $klassciUser['id'];
        $email         = $klassciUser['email'];
        $institutionId = $institution?->id;

        /** @var User $synced */
        $synced = $this->db->transaction(function () use (
            $klassciId,
            $email,
            $institutionId,
            $klassciUser,
            $klassciToken,
            $tenantUrl,
        ): User {
            // Recherche par (klassci_id, institution_id) — la clé unique de la DB
            $user = User::withoutGlobalScope('institution')
                ->where('klassci_id', $klassciId)
                ->where('institution_id', $institutionId)
                ->first();

            // Fallback par email si klassci_id a changé (rare mais possible)
            if (!$user) {
                $user = User::withoutGlobalScope('institution')
                    ->where('email', $email)
                    ->where('institution_id', $institutionId)
                    ->first();
            }

            $klassciRole = $klassciUser['role'] ?? 'etudiant';

            // Champs propagés sur CHAQUE login (CREATE + UPDATE).
            // Email reste syncé au login parce que l'utilisateur engage activement
            // sa session KLASSCI à cet instant — l'asymétrie avec le re-sync passif
            // est détaillée dans `.claude/specs/critical-05-klassci-role-separation/design.md` §4.
            $commonData = [
                'klassci_id'         => $klassciId,
                'name'               => $klassciUser['nom'] ?? $klassciUser['name'] ?? 'User',
                'email'              => $email,
                'klassci_role'       => $klassciRole,
                'klassci_token'      => $klassciToken,
                'klassci_tenant_url' => $tenantUrl,
                'klassci_data'       => json_encode(array_merge($klassciUser, ['_lms_tenant_url' => $tenantUrl])),
                'last_klassci_sync'  => now(),
                'institution_id'     => $institutionId,
            ];

            if ($user) {
                // SÉCURITÉ — REQ-3 de la spec critical-05 :
                // Pour un user existant, `role` LMS reste figé. Seul `klassci_role`
                // capture la valeur de KLASSCI. Le contrôle de `role` LMS appartient
                // exclusivement à l'administration LMS.
                $user->update($commonData);
            } else {
                // SÉCURITÉ — initialisation autorisée d'un nouvel utilisateur.
                //   • `role` LMS — REQ-3 spec critical-05 : seul chemin où KLASSCI peut
                //                  initialiser l'autorisation LMS d'un user découvert
                //   • `klassci_enseignant_id` — REQ-3 spec klassci-enseignant-id-separation :
                //                  initialisation write-once. Plus jamais réécrit (ni au login
                //                  d'un user existant, ni au re-sync passif 24h). Source d'autorité
                //                  unique pour l'ownership évaluations (issue #119).
                $user = User::withoutGlobalScope('institution')->create(array_merge($commonData, [
                    'role'                  => $klassciRole,
                    'klassci_enseignant_id' => isset($klassciUser['enseignant_id']) && is_numeric($klassciUser['enseignant_id'])
                        ? (int) $klassciUser['enseignant_id']
                        : null,
                    'password'              => $this->hasher->make(uniqid()),
                ]));

                $this->logger->info('Nouvel utilisateur créé depuis KLASSCI', [
                    'user_id'        => $user->id,
                    'email'          => $email,
                    'klassci_id'     => $klassciId,
                    'institution_id' => $institutionId,
                    'tenant'         => $tenantUrl,
                ]);
            }

            // Synchroniser les classes pour les étudiants
            if ($user->isStudent()) {
                $this->syncStudentClasses($user, $klassciToken);
            }

            return $user;
        });

        return $synced;
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

            $dashboard = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'me/dashboard',
                'GET'
            );

            // IMPORTANT: L'API retourne 'classe' (singulier), pas 'classes' (pluriel) —
            // un étudiant n'a qu'une seule classe.
            $data = $dashboard['data'] ?? [];
            /** @var array<string, mixed>|null $classe */
            $classe = is_array($data) && isset($data['classe']) && is_array($data['classe'])
                ? $data['classe']
                : null;

            if ($classe === null) {
                $this->logger->warning("Aucune classe trouvée pour l'étudiant", [
                    'user_id' => $user->id,
                ]);

                return;
            }

            $classeId = $classe['id'] ?? null;
            $classeNom = $classe['name'] ?? $classe['libelle'] ?? null;
            $classeLibelle = $classe['libelle'] ?? $classe['name'] ?? null;

            $this->logger->info('Classe KLASSCI récupérée', [
                'user_id'    => $user->id,
                'classe_id'  => $classeId,
                'classe_nom' => $classeNom ?? 'N/A',
            ]);

            // Supprimer les anciennes classes (pour éviter les doublons)
            $user->klassciClasses()->delete();

            // Sauvegarder la classe
            UserClass::create([
                'user_id'           => $user->id,
                'klassci_classe_id' => $classeId,
                'classe_nom'        => $classeNom,
                'classe_libelle'    => $classeLibelle,
                'classe_data'       => $classe,
                'synced_at'         => now(),
            ]);

            $this->logger->info('Classe synchronisée avec succès', [
                'user_id'   => $user->id,
                'classe_id' => $classeId,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la synchronisation des classes', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
        }
    }
}
