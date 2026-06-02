<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Models\Classe;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Psr\Log\LoggerInterface;

/**
 * ClasseStudentsSynchronizer
 *
 * Synchronise la liste d'étudiants d'une classe (création/maj users locaux +
 * inscriptions dans le pivot `classe_etudiant`). Extrait verbatim de
 * `ClasseSyncService::syncClasseStudents()` lors du split SRP (§1.1).
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `KlassciProxyService`, `TenantManager` et
 * `LoggerInterface` (PSR-3). Pas de Facade `Log::` ni de `app()` Service
 * Locator dans le code métier — c'est même une amélioration vs l'ancien
 * `ClasseSyncService` qui résolvait `TenantManager` via `app(...)`.
 *
 * ## PERF-05 (PR #160) — cleanup @temp.local
 *
 * Avant : si KLASSCI ne renvoyait pas d'email, on fabriquait
 * `etudiant{id}@temp.local` comme placeholder ET on l'utilisait pour le
 * fallback lookup. Conséquences :
 *   - pollution de la table users avec des emails fake
 *   - risque de match faux positif avec un vrai étudiant ayant cet email
 *   - users incapables de se connecter
 *   - fuite d'info (l'email construit révélait l'existence d'un user pour
 *     un klassci_id donné)
 *
 * Après : la colonne `email` est nullable depuis la migration 2025-10-19.
 * On ne fabrique plus d'email placeholder. Le fallback lookup par email
 * n'est tenté que si KLASSCI nous en fournit un.
 *
 * ## SECURITY (PR #173) — fail-secure tenant
 *
 * La table pivot `classe_etudiant` n'a pas de modèle Eloquent, donc pas de
 * scope `BelongsToInstitution`. Le filtre `institution_id` est obligatoire
 * et fail-secure : si le tenant n'est pas résolu, on laisse remonter la
 * RuntimeException de `TenantManager::getResolved()` plutôt que de risquer
 * un cross-tenant via un `->when()` silencieux. Ce code n'est appelé que
 * depuis le sync KLASSCI dans un contexte authentifié — le tenant DOIT
 * être résolu.
 *
 * @see ClasseSyncService::syncUserClasses() — orchestrateur appelant
 */
final class ClasseStudentsSynchronizer
{
    public function __construct(
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Synchroniser les étudiants d'une classe.
     *
     * @param Classe $classe Classe locale
     * @param array<int, array<string, mixed>> $etudiantsData Données depuis Klassci
     * @param string $klassciToken Token pour requêtes additionnelles (conservé
     *     pour symétrie de signature — non utilisé directement mais préservé
     *     pour compatibilité avec d'éventuelles évolutions du fetch)
     * @return array{students_synced: int, enrollments_created: int}
     */
    public function sync(Classe $classe, array $etudiantsData, string $klassciToken): array
    {
        $stats = [
            'students_synced' => 0,
            'enrollments_created' => 0,
        ];

        $institutionId = $this->tenantManager->getResolved();

        foreach ($etudiantsData as $etudiantData) {
            try {
                $student = $this->resolveOrCreateStudent($etudiantData, $stats);

                $this->upsertEnrollment($classe, $student, $institutionId, $stats);
            } catch (\Exception $e) {
                $this->logger->error('Erreur sync étudiant', [
                    'classe_id' => $classe->id,
                    'etudiant_id' => $etudiantData['id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Trouve l'étudiant local (par klassci_id, puis email si fourni) ou le
     * crée. Met à jour le compteur `students_synced` quand l'étudiant n'était
     * pas encore liée par `klassci_id`.
     *
     * @param array<string, mixed> $etudiantData
     * @param array{students_synced: int, enrollments_created: int} $stats
     */
    private function resolveOrCreateStudent(array $etudiantData, array &$stats): User
    {
        // Vérifier si l'étudiant existe localement par klassci_id
        $student = User::where('klassci_id', $etudiantData['id'])->first();

        if ($student) {
            return $student;
        }

        // PERF-05 — voir docblock de classe pour le rationale @temp.local.
        $email = isset($etudiantData['email']) && is_string($etudiantData['email']) && $etudiantData['email'] !== ''
            ? $etudiantData['email']
            : null;

        // Fallback lookup par email uniquement si KLASSCI nous en a fourni un.
        if ($email !== null) {
            $student = User::where('email', $email)->where('role', 'etudiant')->first();
        }

        if ($student) {
            // Mettre à jour le klassci_id de l'étudiant existant
            $student->update(['klassci_id' => $etudiantData['id']]);
            $this->logger->info('Klassci ID ajouté à étudiant existant', [
                'user_id' => $student->id,
                'email' => $email,
                'klassci_id' => $etudiantData['id'],
            ]);
        } else {
            // Créer l'étudiant local
            // L'API peut retourner "nom_complet" ou "nom"+"prenom" séparés
            $name = $etudiantData['nom_complet']
                ?? ($etudiantData['nom'] ?? '') . ' ' . ($etudiantData['prenom'] ?? '')
                ?? 'Étudiant';
            $name = trim($name);

            $student = User::create([
                'klassci_id' => $etudiantData['id'],
                'name'       => $name,
                'email'      => $email,    // null si KLASSCI n'en a pas fourni
                'role'       => 'etudiant',
                'password'   => bcrypt(bin2hex(random_bytes(16))), // Password aléatoire
            ]);

            $this->logger->info('Nouvel étudiant créé', [
                'user_id'    => $student->id,
                'klassci_id' => $etudiantData['id'],
                'email'      => $email,
            ]);
        }

        $stats['students_synced']++;

        return $student;
    }

    /**
     * Insère ou met à jour l'inscription (pivot `classe_etudiant`).
     *
     * SECURITY — voir docblock de classe pour le rationale fail-secure tenant.
     *
     * @param array{students_synced: int, enrollments_created: int} $stats
     */
    private function upsertEnrollment(Classe $classe, User $student, int $institutionId, array &$stats): void
    {
        $exists = DB::table('classe_etudiant')
            ->where('classe_id', $classe->id)
            ->where('user_id', $student->id)
            ->where('institution_id', $institutionId)
            ->exists();

        if (!$exists) {
            DB::table('classe_etudiant')->insert([
                'classe_id' => $classe->id,
                'user_id' => $student->id,
                'institution_id' => $institutionId,
                'statut' => 'actif',
                'date_inscription' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stats['enrollments_created']++;
        } else {
            // Mettre à jour le statut si nécessaire
            DB::table('classe_etudiant')
                ->where('classe_id', $classe->id)
                ->where('user_id', $student->id)
                ->where('institution_id', $institutionId)
                ->update([
                    'statut' => 'actif',
                    'updated_at' => now(),
                ]);
        }
    }
}
