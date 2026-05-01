<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * UpdateQuizRequest - Update an existing quiz
 *
 * Purpose: Validate & authorize partial quiz updates
 * Authorization: Quiz creator or admin only (owner check + role fallback)
 * 10-year perspective: Partial updates enable incremental quiz refinement without full re-entry, ownership model prevents cross-account contamination
 */
class UpdateQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $quiz = $this->route('quiz');

        if (!$quiz) {
            return false;
        }

        return $user->isAdmin() || $quiz->created_by === $user->id;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'instructions' => 'sometimes|string',
            'duration_minutes' => 'sometimes|integer|min:1',
            'max_attempts' => 'sometimes|integer|min:1',
            'passing_score' => 'sometimes|numeric|min:0|max:100',
            'shuffle_questions' => 'sometimes|boolean',
            'shuffle_answers' => 'sometimes|boolean',
            'show_correct_answers' => 'sometimes|boolean',
            'allow_review' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'title.string' => 'Le titre doit être une chaîne de caractères.',
            'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',
            'duration_minutes.integer' => 'La durée doit être un nombre entier.',
            'duration_minutes.min' => 'La durée doit être au moins 1 minute.',
            'max_attempts.integer' => 'Le nombre maximum de tentatives doit être un nombre entier.',
            'max_attempts.min' => 'Le nombre maximum de tentatives doit être au moins 1.',
            'passing_score.numeric' => 'Le score de réussite doit être un nombre.',
            'passing_score.min' => 'Le score de réussite doit être au minimum 0.',
            'passing_score.max' => 'Le score de réussite ne peut pas dépasser 100.',
            'shuffle_questions.boolean' => 'Le mélange des questions doit être un booléen.',
            'shuffle_answers.boolean' => 'Le mélange des réponses doit être un booléen.',
            'show_correct_answers.boolean' => 'L\'affichage des bonnes réponses doit être un booléen.',
            'allow_review.boolean' => 'L\'autorisation de révision doit être un booléen.',
        ];
    }
}
