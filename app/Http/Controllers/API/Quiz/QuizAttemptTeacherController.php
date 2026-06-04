<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Quiz;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\GetQuizAttemptsRequest;
use App\Http\Requests\GradeAttemptRequest;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\Quiz\QuizAttemptStateService;
use App\Services\Quiz\QuizAttemptTeacherGradeService;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller — endpoints enseignants des tentatives quiz.
 *
 * Split du god-controller `QuizAttemptController` (444l) puis du
 * `QuizAttemptLifecycleService` (372l) en services SRP conformes §1.1 (≤300l).
 * Tenant + ownership enforcés via les FormRequest authorize().
 *
 * @see app/Services/Quiz/QuizAttemptStateService.php
 * @see app/Services/Quiz/QuizAttemptTeacherGradeService.php
 * @see app/Http/Controllers/API/Quiz/QuizAttemptStudentController.php
 */
final class QuizAttemptTeacherController extends AuthenticatedController
{
    public function __construct(
        private readonly QuizAttemptStateService $state,
        private readonly QuizAttemptTeacherGradeService $teacherGrade,
    ) {
    }

    /**
     * GET /api/quizzes/{quiz}/attempts
     */
    public function getAttempts(GetQuizAttemptsRequest $request, Quiz $quiz): JsonResponse
    {
        /** @var array{user_id?:int|string,per_page?:int|string} $filters */
        $filters = array_filter([
            'user_id'  => $request->input('user_id'),
            'per_page' => $request->input('per_page'),
        ], fn ($v): bool => $v !== null);

        $attempts = $this->state->getAttemptsForQuiz($quiz, $filters);

        return response()->json([
            'success' => true,
            'data'    => $attempts,
        ]);
    }

    /**
     * POST /api/quiz-attempts/{id}/grade
     */
    public function gradeAttempt(GradeAttemptRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);
        if ($attempt === null) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // FormRequest GradeAttemptRequest valide déjà points_earned (numeric,
        // min:0, max:points_possible) et feedback (string|null). On extrait via
        // les helpers typés du Request pour satisfaire phpstan strict.
        $pointsEarned = (float) $request->float('points_earned');
        $feedback     = $request->input('feedback');

        $result = $this->teacherGrade->manualGrade(
            $attempt,
            $this->authenticatedUser($request),
            $pointsEarned,
            is_string($feedback) ? $feedback : null,
        );

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data'    => $result['data'],
        ], $result['status']);
    }
}
