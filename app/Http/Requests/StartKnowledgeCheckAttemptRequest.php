<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates knowledge check attempt start (POST /api/knowledge-checks/{id}/start).
 *
 * ## Purpose
 * Student starts a knowledge check quiz.
 * Validates user eligibility and quiz availability.
 *
 * ## Authorization Model
 * Authenticated students only (auth:sanctum).
 * No special role check (all authenticated users can attempt).
 *
 * ## 10-year Consideration
 * Max attempts enforcement managed by KnowledgeCheck::canAttempt() model scope.
 * Time limit (if any) enforced client-side + server-side on submit.
 */
final class StartKnowledgeCheckAttemptRequest extends FormRequest
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
