<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #540 — répare les soumissions historiques dont `student_id` est NULL.
 *
 * `POST /evaluations/{id}/start` créait la soumission avec `klassci_etudiant_id`
 * seul, sans jamais renseigner `student_id`. Tout le code qui lit l'identité
 * LOCALE de l'étudiant — `User::evaluationSubmissions()`, le regroupement par
 * `student_id` de `ReportGenerationService` — ignorait donc silencieusement ces
 * lignes. Le correctif applicatif rend l'invariant vrai pour les nouvelles
 * lignes ; cette migration le rend vrai pour les anciennes.
 *
 * Prudence délibérée : une ligne n'est réparée que si l'étudiant est identifié
 * SANS AMBIGUÏTÉ (exactement un `users` portant ce `klassci_id` dans la même
 * institution). Les cas ambigus restent à NULL — mieux vaut une donnée absente
 * qu'une note rattachée au mauvais étudiant.
 *
 * `DB::table('users')` est utilisé volontairement : le query builder ignore le
 * global scope d'institution et le soft-delete du modèle, or une soumission
 * d'un étudiant depuis désactivé doit rester rattachable.
 */
return new class extends Migration
{
    /** Taille de lot — borne l'empreinte mémoire sur une grosse table. */
    private const CHUNK = 200;

    public function up(): void
    {
        DB::table('evaluation_submissions')
            ->whereNull('student_id')
            ->select('klassci_etudiant_id', 'institution_id')
            ->distinct()
            ->orderBy('institution_id')
            ->orderBy('klassci_etudiant_id')
            ->chunk(self::CHUNK, function ($couples): void {
                foreach ($couples as $couple) {
                    $this->repairCouple(
                        (int) $couple->klassci_etudiant_id,
                        $couple->institution_id === null ? null : (int) $couple->institution_id,
                    );
                }
            });
    }

    /**
     * `down()` volontairement inerte : re-vider `student_id` re-casserait des
     * données réparées sans rien restaurer d'utile. La migration est un
     * correctif de données, pas un changement de schéma réversible.
     */
    public function down(): void
    {
        // Aucune action : voir le commentaire ci-dessus.
    }

    private function repairCouple(int $klassciEtudiantId, ?int $institutionId): void
    {
        $candidates = DB::table('users')
            ->where('klassci_id', $klassciEtudiantId)
            ->when($institutionId !== null, static fn ($query) => $query->where('institution_id', $institutionId))
            ->pluck('id');

        // Zéro candidat (étudiant purgé) ou plusieurs (même klassci_id sur deux
        // comptes) : on ne devine pas, la ligne reste à NULL.
        if ($candidates->count() !== 1) {
            return;
        }

        DB::table('evaluation_submissions')
            ->whereNull('student_id')
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->when(
                $institutionId === null,
                static fn ($query) => $query->whereNull('institution_id'),
                static fn ($query) => $query->where('institution_id', $institutionId),
            )
            ->update(['student_id' => (int) $candidates->first()]);
    }
};
