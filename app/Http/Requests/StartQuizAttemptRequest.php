<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates quiz attempt start (POST /api/quizzes/{quiz}/start-attempt).
 *
 * ## Purpose
 * Student starts a quiz attempt.
 * Validates quiz availability and user eligibility.
 *
 * ## Authorization Model
 * Authenticated students only (auth:sanctum).
 * Quiz must be published and available (timing/access checks in controller).
 *
 * ## 10-year Consideration
 * Quiz availability managed by isAvailable() model scope.
 * Max attempts enforced by canUserAttempt() model method.
 * Time limits (if any) managed per-quiz config.
 */
final class StartQuizAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        // Defense en profondeur (fix E2E #211 flow 5) : le route binding
        // {quiz} n'est pas filtre si le tenant n'est pas resolu — un user
        // du tenant B ne doit pas demarrer un quiz du tenant A.
        $quiz = $this->route('quiz');
        if ($quiz instanceof \App\Models\Quiz
            && $user->institution_id !== null
            && $quiz->institution_id !== $user->institution_id) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
