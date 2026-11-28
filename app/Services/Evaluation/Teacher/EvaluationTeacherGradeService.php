<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Enums\EvaluationSubmissionStatus;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\Evaluation\QuestionsDeLaCopie;

/**
 * #588 — notation manuelle d'une soumission (dissertations).
 */
final class EvaluationTeacherGradeService
{
    public function __construct(
        private readonly EvaluationGradingService $grading,
        private readonly QuestionsDeLaCopie $questionsDeLaCopie,
    ) {}

    /**
     * @param  array<int|string, float|int|string>  $manualPoints
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function grade(
        Evaluation $evaluation,
        EvaluationSubmission $submission,
        User $grader,
        array $manualPoints,
        ?string $feedback,
    ): array {
        if ((int) $submission->evaluation_id !== (int) $evaluation->id) {
            return $this->fail(404, 'Soumission non trouvée');
        }

        // C28 : une copie hors délai (verrouillée à la remise, ou échue sans remise :
        // son statut LU) est visible, jamais notée. L'utilisateur l'a dite « refusée ».
        if ($submission->status === EvaluationSubmissionStatus::Verrouille->value) {
            return $this->fail(409, 'Cette copie a été remise hors délai : elle ne peut pas être notée.', 'evaluation_attempt_locked');
        }

        if (! in_array($submission->status, ['soumis', 'corrige'], true)) {
            return $this->fail(409, 'Cette soumission ne peut pas encore être notée.', 'evaluation_submission_not_gradable');
        }

        // #1702 (D2) : les dissertations POSÉES à cet élève, pas toutes celles de l'évaluation.
        $dissertations = $this->questionsDeLaCopie->de($submission, $evaluation)->filter(
            fn ($question): bool => $this->grading->requiresManualGrading($question)
        );

        if ($dissertations->isEmpty()) {
            return $this->fail(422, 'Cette évaluation n\'a aucune question à notation manuelle.');
        }

        foreach ($dissertations as $question) {
            $raw = $manualPoints[$question->id] ?? $manualPoints[(string) $question->id] ?? null;
            if (! is_numeric($raw)) {
                return $this->fail(422, 'Chaque dissertation doit recevoir des points.');
            }
        }

        $this->grading->manualGrade($submission, $manualPoints, $grader, $feedback);

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'message' => 'Soumission notée',
                'data' => $submission->fresh(['student']),
            ],
        ];
    }

    /**
     * @return array{status:int, payload: array<string, mixed>}
     */
    private function fail(int $status, string $message, ?string $reason = null): array
    {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'message' => $message,
            ] + ($reason === null ? [] : ['reason' => $reason]),
        ];
    }
}
