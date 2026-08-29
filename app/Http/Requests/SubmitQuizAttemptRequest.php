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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['required', 'array'],
            // Chaque réponse est soit un id scalaire (int / string numérique),
            // soit un tableau d'ids (multiple_response). On rejette explicitement
            // les booléens (et autres non-scalaires) pour fermer le type juggling
            // à la notation (#498) — la barrière autoritaire reste QuizGradingService.
            'answers.*' => ['nullable', function (string $attribute, mixed $value, \Closure $fail): void {
                if (is_bool($value)) {
                    $fail('Une réponse ne peut pas être un booléen.');

                    return;
                }

                if (! is_int($value) && ! is_string($value) && ! is_array($value)) {
                    $fail('Format de réponse invalide.');
                }
            }],
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
