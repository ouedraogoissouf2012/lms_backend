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
        return auth()->check();
    }

    public function rules(): array
    {
        return [];
    }
}
