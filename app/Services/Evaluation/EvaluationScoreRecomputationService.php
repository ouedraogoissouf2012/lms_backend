<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Illuminate\Support\Collection;

/**
 * Recalcule les scores d'évaluation faussés par le bug de format #564 : les
 * soumissions historiques ont `answers` stockées en LISTE `[{question_id, answer}]`
 * (lues en MAP par le service de correction → notées 0). Ce service normalise
 * liste→map puis réutilise {@see EvaluationGradingService::calculateScore} (DRY).
 *
 * ## Sécurité
 * - **Dry-run par défaut** : aucune écriture sans `$apply === true`.
 * - **Fail-closed** : les évaluations à correction manuelle (dissertation) sont
 *   SKIPPÉES — leur note ne peut pas être finalisée automatiquement (voir #588),
 *   donc jamais recalculée pour un re-push KLASSCI.
 * - **Recalcul, jamais mise à 20 aveugle** : une note ne change que si le calcul
 *   diffère de la note stockée (certains 0 sont légitimes).
 *
 * ## Perf
 * Regroupe par évaluation (`questions` eager-loadées une fois par éval).
 * `calculateScore` re-requête l'évaluation par soumission : N+1 ASSUMÉ et
 * acceptable pour une commande de MAINTENANCE ponctuelle hors ligne (§1.4 borne
 * les chemins requête, pas un batch offline). Bornable via `$evaluationId` /
 * `$institutionId`.
 */
final class EvaluationScoreRecomputationService
{
    /** Statuts de soumission comptabilisables (comme le sync KLASSCI). */
    private const GRADABLE_STATUSES = ['soumis', 'corrige'];

    public function __construct(private readonly EvaluationGradingService $grading) {}

    /**
     * @return list<array{submission_id:int, evaluation_id:int, outcome:string, old_note:float, new_note:float}>
     */
    public function recompute(bool $apply, ?int $evaluationId = null, ?int $institutionId = null): array
    {
        $results = [];

        $evaluations = Evaluation::query()
            ->with('questions')
            ->when($evaluationId !== null, fn ($query) => $query->where('id', $evaluationId))
            ->when($institutionId !== null, fn ($query) => $query->where('institution_id', $institutionId))
            ->get();

        foreach ($evaluations as $evaluation) {
            $isManual = $this->evaluationRequiresManualGrading($evaluation);
            foreach ($this->candidateSubmissions($evaluation) as $submission) {
                $results[] = $this->recomputeOne($submission, $isManual, $apply);
            }
        }

        return $results;
    }

    private function evaluationRequiresManualGrading(Evaluation $evaluation): bool
    {
        return $evaluation->questions->contains(
            fn ($question): bool => $this->grading->requiresManualGrading($question)
        );
    }

    /**
     * @return Collection<int, EvaluationSubmission>
     */
    private function candidateSubmissions(Evaluation $evaluation): Collection
    {
        return $evaluation->submissions()
            ->whereIn('status', self::GRADABLE_STATUSES)
            ->whereNotNull('answers')
            ->get();
    }

    /**
     * @return array{submission_id:int, evaluation_id:int, outcome:string, old_note:float, new_note:float}
     */
    private function recomputeOne(EvaluationSubmission $submission, bool $isManual, bool $apply): array
    {
        $oldNote = (float) $submission->note_sur_20;

        if ($isManual) {
            return $this->result($submission, 'skipped_manual', $oldNote, $oldNote);
        }

        $submission->answers = $this->normalizeToMap($submission->answers ?? []);
        $this->grading->calculateScore($submission);
        $newNote = (float) $submission->note_sur_20;

        if (abs($newNote - $oldNote) < 0.001) {
            return $this->result($submission, 'unchanged', $oldNote, $newNote);
        }

        if ($apply) {
            $submission->save();
        }

        return $this->result($submission, 'changed', $oldNote, $newNote);
    }

    /**
     * Normalise les réponses LISTE `[{question_id, answer}]` en MAP
     * `{question_id: réponse}`. Une map déjà au bon format est renvoyée telle quelle.
     *
     * @param  array<int|string, mixed>  $answers
     * @return array<int|string, mixed>
     */
    private function normalizeToMap(array $answers): array
    {
        $map = [];
        foreach ($answers as $key => $value) {
            if (is_array($value) && array_key_exists('question_id', $value) && array_key_exists('answer', $value)) {
                $map[$value['question_id']] = $value['answer'];
            } else {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * @return array{submission_id:int, evaluation_id:int, outcome:string, old_note:float, new_note:float}
     */
    private function result(EvaluationSubmission $submission, string $outcome, float $oldNote, float $newNote): array
    {
        return [
            'submission_id' => (int) $submission->id,
            'evaluation_id' => (int) $submission->evaluation_id,
            'outcome' => $outcome,
            'old_note' => $oldNote,
            'new_note' => $newNote,
        ];
    }
}
