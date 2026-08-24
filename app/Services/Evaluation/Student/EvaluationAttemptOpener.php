<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Attempts\AttemptConflictGuard;

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
        return EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->where('status', 'en_cours')
            ->first();
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

        $consumed = EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->count();

        return $consumed >= $evaluation->max_attempts;
    }

    /**
     * `max + 1`, jamais `count + 1` : une tentative supprimée laisse un trou, et
     * `count + 1` re-proposerait alors un numéro déjà pris — collision frontale
     * avec `eval_sub_unique`, remontée en 500 (même correctif que le quiz #211).
     */
    private function nextAttemptNumber(Evaluation $evaluation, int $klassciEtudiantId): int
    {
        $highest = EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->max('attempt');

        return is_numeric($highest) ? ((int) $highest) + 1 : 1;
    }
}
