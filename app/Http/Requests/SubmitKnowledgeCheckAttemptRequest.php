<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates knowledge check submission (POST /api/knowledge-checks/{id}/submit).
 *
 * ## Purpose
 * Student submits answers to knowledge check quiz.
 * Validates answer format and time tracking.
 *
 * ## Authorization Model
 * Authenticated students only (auth:sanctum).
 * Answers are marked with user_id automatically.
 *
 * ## 10-year Consideration
 * Answers array indexed by question index (match question order in DB).
 * Time spent (seconds) used for analytics, not enforcement.
 * If question shuffling changes, validate answer mapping separately.
 */
final class SubmitKnowledgeCheckAttemptRequest extends FormRequest
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
            'time_spent_seconds' => 'required|integer|min:0',
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
            'time_spent_seconds.required' => 'Le temps passé est requis',
            'time_spent_seconds.integer' => 'Le temps doit être un entier',
            'time_spent_seconds.min' => 'Le temps doit être positif ou zéro',
        ];
    }
}
