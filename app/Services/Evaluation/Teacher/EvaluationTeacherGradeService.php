<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;

/**
 * #588 — notation manuelle d'une soumission (dissertations).
 */
final class EvaluationTeacherGradeService
{
    public function __construct(
        private readonly EvaluationGradingService $grading,
    ) {
    }

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

        if (! in_array($submission->status, ['soumis', 'corrige'], true)) {
            return $this->fail(409, 'Cette soumission ne peut pas encore être notée.');
        }

        $dissertations = $evaluation->questions->filter(
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
    private function fail(int $status, string $message): array
    {
        return [
            'status' => $status,
            'payload' => [
                'success' => false,
                'message' => $message,
            ],
        ];
    }
}
