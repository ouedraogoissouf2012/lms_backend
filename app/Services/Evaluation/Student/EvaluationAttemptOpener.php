<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Attempts\AttemptConflictGuard;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ouverture d'une soumission d'évaluation pour un étudiant : reprise de la
 * tentative en cours, application du quota, insertion sous le filet d'unicité.
 *
 * ## Pourquoi ce service existe (#540)
 *
 * L'ouverture était dupliquée en deux implémentations **incompatibles** :
 *
 * | | clé de recherche | `student_id` écrit | numéro de tentative |
 * |---|---|---|---|
 * | `POST /start` (ex-`EvaluationAttemptStateService`) | `klassci_etudiant_id` | jamais | `count + 1` |
 * | `POST /submit` (ex-controller) | `student_id` | oui | `1` en dur |
 *
 * Conséquence mesurée : `/start` créait une ligne que `/submit` ne retrouvait
 * pas, en recréait une seconde avec `attempt = 1`, violait `eval_sub_unique` et
 * remontait en **500** — le parcours nominal était cassé à 100 %.
 *
 * Les deux endpoints passent désormais par ce point d'entrée unique, qui
 * renseigne les **deux** identités (`student_id` local pour les rapports et les
 * relations Eloquent, `klassci_etudiant_id` pour la synchronisation KLASSCI et
 * pour l'unique de la table).
 *
 * ## Statuts retournés
 *
 * - `ok`            → soumission ouverte (`resumed` distingue reprise et création)
 * - `no_klassci_id` → étudiant non synchronisé : refus AVANT toute insertion
 * - `max_attempts`  → quota atteint hors mode entraînement
 * - `conflict`      → course perdue et rien à reprendre (409, jamais 500)
 *
 * @see \App\Services\Attempts\AttemptConflictGuard
 * @see .claude/specs/540-attempt-quota-race/design.md
 */
final class EvaluationAttemptOpener
{
    public function __construct(
        private readonly AttemptConflictGuard $conflictGuard,
    ) {
    }

    /**
     * Ouverture depuis `POST /submit`, où l'étudiant n'a pas forcément appelé
     * `/start` — un client historique peut soumettre directement.
     *
     * Différence avec {@see self::open()} : une nouvelle tentative n'est créée
     * QUE si l'étudiant n'en a aucune. Sinon un double-clic (ou un ré-essai
     * client) rouvrait et notait une tentative supplémentaire — la première
     * venant de passer en `soumis`, elle n'était plus reprenable — dépensant
     * silencieusement 2 essais sur 3. Reprendre une seconde tentative exige
     * désormais un `/start` explicite.
     *
     * @return array{status: 'ok', submission: EvaluationSubmission, resumed: bool}|array{status: 'no_klassci_id'}|array{status: 'max_attempts', message: string}|array{status: 'conflict'}|array{status: 'needs_start'}
     */
    public function openForSubmission(Evaluation $evaluation, User $user, bool $isPracticeMode): array
    {
        $klassciEtudiantId = $user->klassci_id;
        if ($klassciEtudiantId === null) {
            return ['status' => 'no_klassci_id'];
        }

        $active = $this->activeSubmission($evaluation, $klassciEtudiantId);
        if ($active !== null) {
            return ['status' => 'ok', 'submission' => $active, 'resumed' => true];
        }

        if ($this->submissionKeyspace($evaluation, $klassciEtudiantId)->exists()) {
            return ['status' => 'needs_start'];
        }

        return $this->open($evaluation, $user, $isPracticeMode);
    }

    /**
     * Ouverture depuis `POST /start` : reprise, quota, puis insertion.
     *
     * Union de formes plutôt qu'un tableau à clés optionnelles : l'appelant qui
     * teste `status === 'ok'` obtient ainsi la garantie statique que
     * `submission` existe (level 9), sans `??` défensif ni assertion.
     *
     * @return array{status: 'ok', submission: EvaluationSubmission, resumed: bool}|array{status: 'no_klassci_id'}|array{status: 'max_attempts', message: string}|array{status: 'conflict'}
     */
    public function open(Evaluation $evaluation, User $user, bool $isPracticeMode): array
    {
        $klassciEtudiantId = $user->klassci_id;
        if ($klassciEtudiantId === null) {
            // `klassci_etudiant_id` est NOT NULL : sans ce garde-fou, l'insertion
            // partait en violation de contrainte, donc en 500 (#540 R2.3).
            return ['status' => 'no_klassci_id'];
        }

        $active = $this->activeSubmission($evaluation, $klassciEtudiantId);
        if ($active !== null) {
            return ['status' => 'ok', 'submission' => $active, 'resumed' => true];
        }

        if ($this->quotaReached($evaluation, $klassciEtudiantId, $isPracticeMode)) {
            return [
                'status' => 'max_attempts',
                'message' => 'Nombre maximum de tentatives atteint (' . $evaluation->max_attempts . ')',
            ];
        }

        return $this->insertSubmission($evaluation, $user, $klassciEtudiantId, $isPracticeMode);
    }

    /**
     * Insère la nouvelle soumission sous le filet d'unicité de la base.
     *
     * En cas de course perdue, la tentative gagnante est relue : si elle est
     * encore ouverte, l'étudiant la reprend (cas du double-clic, 200) ; sinon le
     * conflit est signalé tel quel (409).
     *
     * @return array{status: 'ok', submission: EvaluationSubmission, resumed: bool}|array{status: 'conflict'}
     */
    private function insertSubmission(
        Evaluation $evaluation,
        User $user,
        int $klassciEtudiantId,
        bool $isPracticeMode
    ): array {
        $attempt = $this->nextAttemptNumber($evaluation, $klassciEtudiantId);

        $outcome = $this->conflictGuard->insert(
            fn (): EvaluationSubmission => EvaluationSubmission::create([
                'evaluation_id' => $evaluation->id,
                'student_id' => $user->id,
                'klassci_etudiant_id' => $klassciEtudiantId,
                'attempt' => $attempt,
                'status' => 'en_cours',
                'started_at' => now(),
                // Scope tenant explicite hérité de l'évaluation (fix E2E #211).
                'institution_id' => $evaluation->institution_id,
                'feedback' => $isPracticeMode ? '[PRACTICE] Entraînement - note non officielle' : null,
            ]),
            fn (): ?EvaluationSubmission => $this->activeSubmission($evaluation, $klassciEtudiantId),
        );

        if ($outcome === null) {
            return ['status' => 'conflict'];
        }

        return ['status' => 'ok', 'submission' => $outcome->attempt(), 'resumed' => $outcome->isResolved()];
    }

    /** Tentative encore ouverte de l'étudiant sur cette évaluation, s'il y en a une. */
    private function activeSubmission(Evaluation $evaluation, int $klassciEtudiantId): ?EvaluationSubmission
    {
        return $this->submissionKeyspace($evaluation, $klassciEtudiantId)
            ->where('status', 'en_cours')
            ->first();
    }

    /**
     * Soumissions du couple (évaluation, étudiant) telles que **la base** les
     * voit — global scope `institution` retiré.
     *
     * `eval_sub_unique` porte sur `(evaluation_id, klassci_etudiant_id, attempt)`
     * et ignore `institution_id`. Or cette colonne a été ajoutée nullable et
     * **sans backfill** (`2026_02_11_000002_add_institution_id_to_all_tables`),
     * puis laissée nullable à dessein (#583) : toute soumission antérieure à
     * février 2026 est invisible au scope dès qu'un tenant est résolu, alors
     * que l'index continue de la faire respecter. Sans ce retrait, `max + 1`
     * re-proposerait un numéro déjà pris et l'étudiant resterait bloqué en 409.
     *
     * Sans risque cross-tenant : le filtre est ancré sur `evaluation_id`, déjà
     * rattaché à une seule institution.
     *
     * @return \Illuminate\Database\Eloquent\Builder<EvaluationSubmission>
     */
    private function submissionKeyspace(Evaluation $evaluation, int $klassciEtudiantId): Builder
    {
        return EvaluationSubmission::withoutGlobalScope('institution')
            ->where('evaluation_id', $evaluation->id)
            ->where('klassci_etudiant_id', $klassciEtudiantId);
    }

    /**
     * Le quota est-il atteint ? Compte TOUTES les tentatives déjà ouvertes par
     * l'étudiant, pas seulement les finalisées : une tentative consomme le droit
     * d'essai dès son ouverture, comme dans un examen réel. Le mode entraînement
     * (évaluation terminée) n'est pas soumis au quota.
     */
    private function quotaReached(Evaluation $evaluation, int $klassciEtudiantId, bool $isPracticeMode): bool
    {
        if ($isPracticeMode || ! $evaluation->max_attempts) {
            return false;
        }

        $consumed = $this->submissionKeyspace($evaluation, $klassciEtudiantId)->count();

        return $consumed >= $evaluation->max_attempts;
    }

    /**
     * `max + 1`, jamais `count + 1` : une tentative supprimée laisse un trou, et
     * `count + 1` re-proposerait alors un numéro déjà pris — collision frontale
     * avec `eval_sub_unique`, remontée en 500 (même correctif que le quiz #211).
     */
    private function nextAttemptNumber(Evaluation $evaluation, int $klassciEtudiantId): int
    {
        $highest = $this->submissionKeyspace($evaluation, $klassciEtudiantId)->max('attempt');

        return is_numeric($highest) ? ((int) $highest) + 1 : 1;
    }
}
