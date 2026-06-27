<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Quiz;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\CheckTimeRemainingRequest;
use App\Http\Requests\SaveQuizProgressRequest;
use App\Http\Requests\ShowAttemptRequest;
use App\Http\Requests\StartQuizAttemptRequest;
use App\Http\Requests\SubmitQuizAttemptRequest;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Services\Quiz\QuizAttemptStartSubmitService;
use App\Services\Quiz\QuizAttemptStateService;
use Illuminate\Http\JsonResponse;

/**
 * Thin controller — endpoints étudiants des tentatives quiz.
 *
 * Split du god-controller `QuizAttemptController` (444l) puis du
 * `QuizAttemptLifecycleService` (372l) en services SRP conformes §1.1 (≤300l).
 * Le controller se borne à :
 *   1. extraire l'utilisateur authentifié,
 *   2. déléguer au service approprié,
 *   3. transcrire le résultat normalisé en `JsonResponse`.
 *
 * Authorization (tenant + ownership) est portée par les `FormRequest::authorize`
 * — voir #87 fix.
 *
 * @see app/Services/Quiz/QuizAttemptStartSubmitService.php
 * @see app/Services/Quiz/QuizAttemptStateService.php
 * @see app/Http/Controllers/API/Quiz/QuizAttemptTeacherController.php
 */
final class QuizAttemptStudentController extends AuthenticatedController
{
    public function __construct(
        private readonly QuizAttemptStartSubmitService $startSubmit,
        private readonly QuizAttemptStateService $state,
    ) {
    }

    /**
     * POST /api/quizzes/{quiz}/start
     */
    public function startAttempt(StartQuizAttemptRequest $request, Quiz $quiz): JsonResponse
    {
        $user   = $this->authenticatedUser($request);
        $result = $this->startSubmit->startAttempt($quiz, $user);

        return $this->toJson($result);
    }

    /**
     * POST /api/quiz-attempts/{id}/submit
     */
    public function submitAttempt(SubmitQuizAttemptRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);
        if ($attempt === null) {
            return $this->notFound();
        }

        /** @var array<int|string,mixed> $answers */
        $answers = is_array($request->input('answers')) ? $request->input('answers') : [];

        $result = $this->startSubmit->submitAttempt(
            $attempt,
            $this->authenticatedUser($request),
            $answers,
        );

        return $this->toJson($result);
    }

    /**
     * GET /api/quiz-attempts/{id}
     */
    public function showAttempt(ShowAttemptRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with(['quiz', 'user:id,name,email'])->find($id);
        if ($attempt === null) {
            return $this->notFound();
        }

        $result = $this->state->showAttempt(
            $attempt,
            $this->authenticatedUser($request),
        );

        // showAttempt place le modèle directement dans `data`, pas un tableau.
        return response()->json([
            'success' => $result['success'],
            'data'    => $result['data'],
        ], $result['status']);
    }

    /**
     * GET /api/quiz-attempts/{id}/time-remaining
     */
    public function checkTimeRemaining(CheckTimeRemainingRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);
        if ($attempt === null) {
            return $this->notFound();
        }

        $result = $this->state->checkTimeRemaining(
            $attempt,
            $this->authenticatedUser($request),
        );

        return $this->toJson($result);
    }

    /**
     * POST /api/quiz-attempts/{id}/save-progress
     */
    public function saveProgress(SaveQuizProgressRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);
        if ($attempt === null) {
            return $this->notFound();
        }

        /** @var array<int|string,mixed> $answers */
        $answers = is_array($request->input('answers')) ? $request->input('answers') : [];

        $result = $this->state->saveProgress(
            $attempt,
            $this->authenticatedUser($request),
            $answers,
        );

        return $this->toJson($result);
    }

    /**
     * Transcrit le contrat normalisé du service en réponse JSON.
     *
     * @param  array{status:int,success:bool,message:?string,data:mixed,errors:?array<string,mixed>}  $result
     */
    private function toJson(array $result): JsonResponse
    {
        // Non migré vers le trait : `success` est DYNAMIQUE (porté par le
        // contrat normalisé du service, donc true OU false) avec un status
        // variable — aucune fabrique du trait ne couvre ce dispatcher en un
        // appel. Conservé inline (axe #1 « DRY-only »).
        $payload = ['success' => $result['success']];

        if ($result['message'] !== null) {
            $payload['message'] = $result['message'];
        }

        if ($result['data'] !== null) {
            $payload['data'] = $result['data'];
        }

        if (! empty($result['errors'])) {
            $payload['errors'] = $result['errors'];
        }

        return response()->json($payload, $result['status']);
    }

    private function notFound(): JsonResponse
    {
        return $this->errorResponse('Tentative non trouvée', 404);
    }
}
