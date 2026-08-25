<?php

declare(strict_types=1);

namespace App\Services\Sync\Classes;

use App\Models\Classe;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
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
 * scope `BelongsToInstitution`. Le tenant reste fail-secure : s'il n'est pas
 * résolu, on laisse remonter la RuntimeException de
 * `TenantManager::getResolved()` plutôt que d'écrire une ligne non rattachée.
 * Ce code n'est appelé que depuis le sync KLASSCI dans un contexte authentifié —
 * le tenant DOIT être résolu.
 *
 * ## Intégrité de l'inscription (#541)
 *
 * `classe_etudiant` porte désormais un unique EFFECTIF `(classe_id, user_id)` —
 * l'ancien `(classe_id, user_id, annee_universitaire_id)` ne contraignait rien,
 * la colonne d'année n'étant jamais écrite. La recherche d'existence doit donc
 * coller EXACTEMENT à cet index : y ajouter `institution_id` ferait manquer une
 * ligne héritée à `institution_id` NULL, et l'insertion suivante serait rejetée
 * par la contrainte. La garde tenant de PR #173 n'est pas perdue pour autant —
 * elle est déplacée sur la ligne LUE ({@see belongsToTenant()}) : un
 * rattachement absent est réparé, celui d'une autre institution n'est JAMAIS
 * réécrit. Une course perdue se solde par un rejet de la BASE, non plus par un
 * doublon. `annee_universitaire_id` est enfin alimentée, depuis la classe.
 *
 * @see ClasseSyncService::syncUserClasses() — orchestrateur appelant
 */
final class ClasseStudentsSynchronizer
{
    public function __construct(
        private readonly DatabaseManager $db,
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
        // Les colonnes de correspondance sont EXACTEMENT celles de l'index unique
        // `classe_etudiant_classe_user_unique` (#541). C'est la condition pour que
        // l'écriture ne puisse pas violer la contrainte : filtrer en plus sur
        // `institution_id` ferait manquer une ligne existante rattachée à un
        // `institution_id` NULL (héritage pré-multi-tenant), et l'insertion qui
        // suivrait serait alors rejetée par l'unique.
        $match = [
            'classe_id' => $classe->id,
            'user_id' => $student->id,
        ];

        $existing = $this->db->table('classe_etudiant')->where($match)->first();

        if ($existing === null) {
            try {
                $this->db->table('classe_etudiant')->insert($match + [
                    'institution_id' => $institutionId,
                    'annee_universitaire_id' => $classe->annee_universitaire_id,
                    'statut' => 'actif',
                    'date_inscription' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $stats['enrollments_created']++;

                return;
            } catch (UniqueConstraintViolationException) {
                // Course perdue face à un sync concurrent : depuis #541 c'est la
                // BASE qui tranche, la ligne existe désormais — on bascule sur la
                // mise à jour. SEULE cette exception est interceptée ; toute autre
                // erreur (clé étrangère, colonne) doit continuer à remonter.
                $existing = $this->db->table('classe_etudiant')->where($match)->first();
            }
        }

        if ($existing === null || ! $this->belongsToTenant($existing, $institutionId, $classe, $student)) {
            return;
        }

        $this->db->table('classe_etudiant')->where($match)->update([
            // Répare le rattachement d'une ligne héritée à `institution_id` NULL.
            // Jamais une ré-affectation : le cas « autre institution » est écarté
            // au-dessus.
            'institution_id' => $institutionId,
            'annee_universitaire_id' => $classe->annee_universitaire_id,
            'statut' => 'actif',
            'updated_at' => now(),
        ]);
    }

    /**
     * La ligne existante appartient-elle bien au tenant courant ?
     *
     * FAIL-SECURE (garde de PR #173, conservée sous une autre forme) : la
     * correspondance ne peut plus inclure `institution_id` — elle doit coller à
     * l'index unique `(classe_id, user_id)` (#541), sans quoi une ligne héritée à
     * `institution_id` NULL serait manquée et l'insertion suivante rejetée. La
     * vérification tenant est donc faite ICI, sur la ligne lue : on répare un
     * rattachement ABSENT, on ne réécrit JAMAIS celui d'une autre institution.
     * Une telle ligne est une anomalie (une classe n'appartient qu'à un tenant) :
     * on la signale et on n'y touche pas.
     */
    private function belongsToTenant(object $enrollment, int $institutionId, Classe $classe, User $student): bool
    {
        $owner = $enrollment->institution_id ?? null;

        if ($owner === null || (int) $owner === $institutionId) {
            return true;
        }

        $this->logger->error('Inscription rattachée à une autre institution — laissée intacte', [
            'classe_id' => $classe->id,
            'user_id' => $student->id,
            'institution_attendue' => $institutionId,
            'institution_trouvee' => (int) $owner,
        ]);

        return false;
    }
}
