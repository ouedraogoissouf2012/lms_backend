<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates quiz attempt submission (POST /api/quiz-attempts/{id}/submit).
 *
 * ## Purpose
 * Student submits completed quiz attempt.
 * Validates answer format before grading.
 *
 * ## Authorization Model
 * Student who owns the attempt (attempt->user_id === auth()->id()).
 * Ownership verified in controller via attempt lookup.
 *
 * ## 10-year Consideration
 * Answers array indexed by question ID or position.
 * Time expiration checked server-side (guard against client manipulation).
 * Submitted attempts cannot be modified (status becomes graded/submitted).
 */
final class SubmitQuizAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'answers' => 'required|array',
        ];
    }

    public function messages(): array
    {
        return [
            'answers.required' => 'Les réponses sont requises',
            'answers.array' => 'Les réponses doivent être un tableau',
        ];
    }
}
