<?php

declare(strict_types=1);

namespace App\Services\Quiz\Concerns;

use App\Models\QuizAttempt;
use App\Services\Quiz\QuizGradingService;

/**
 * Helpers partagés par les services issus du split de
 * `QuizAttemptLifecycleService` (§1.1 ≤300l).
 *
 * - `failure()` : normalise le contrat de retour échec (status, success=false…)
 * - `buildQuestionsWithResults()` : assemble la grille de résultats détaillée
 *
 * Le trait évite la duplication entre les 3 services SRP du domaine quiz
 * attempt tout en gardant chaque service indépendant et testable.
 *
 * @see app/Services/Quiz/QuizAttemptStartSubmitService.php
 * @see app/Services/Quiz/QuizAttemptStateService.php
 * @see app/Services/Quiz/QuizAttemptTeacherGradeService.php
 */
trait BuildsAttemptResponses
{
    /**
     * @return array{status:int,success:bool,message:?string,data:null,errors:?array<string,mixed>}
     */
    protected function failure(int $status, string $message): array
    {
        return [
            'status'  => $status,
            'success' => false,
            'message' => $message,
            'data'    => null,
            'errors'  => null,
        ];
    }

    /**
     * Assemble la grille question→résultat (extrait du god-controller #176).
     *
     * @return array<int,array{question:mixed,user_answer:mixed,is_correct:?bool,points_earned:float,correct_answers:mixed}>
     */
    protected function buildQuestionsWithResults(
        QuizAttempt $attempt,
        QuizGradingService $grading,
    ): array {
        $quiz = $attempt->quiz;
        if ($quiz === null) {
            return [];
        }

        $questions   = $quiz->questions()->with('answers')->ordered()->get();
        $userAnswers = $attempt->answers ?? [];

        return $questions->map(function ($question) use ($userAnswers, $grading): array {
            $userAnswer = $userAnswers[$question->id] ?? null;

            return [
                'question'        => $question,
                'user_answer'     => $userAnswer,
                'is_correct'      => $grading->checkAnswer($question, $userAnswer),
                'points_earned'   => $grading->calculatePoints($question, $userAnswer),
                'correct_answers' => $question->getCorrectAnswers(),
            ];
        })->toArray();
    }
}
