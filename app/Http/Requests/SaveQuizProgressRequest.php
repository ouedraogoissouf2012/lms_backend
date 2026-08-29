<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates quiz progress save (POST /api/quiz-attempts/{id}/save-progress).
 *
 * ## Purpose
 * Student saves partial quiz answers during attempt.
 * Auto-save feature to prevent data loss on connection issues.
 *
 * ## Authorization Model
 * Student who owns the attempt (attempt->user_id === auth()->id()).
 * Ownership verified in controller via attempt lookup.
 *
 * ## 10-year Consideration
 * Partial answers persisted (not graded until final submit).
 * Time expiration enforced (auto-submit if time exceeds limit).
 * Save updates 'answers' column, preserves attempt status as in_progress.
 */
final class SaveQuizProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => 'required|array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.required' => 'Les réponses sont requises',
            'answers.array' => 'Les réponses doivent être un tableau',
        ];
    }
}
