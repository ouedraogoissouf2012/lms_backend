<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * StoreQuizRequest - Create a new quiz
 *
 * Purpose: Validate & authorize quiz creation
 * Authorization: Enseignants, coordinateurs, and superAdmins only (enforced by middleware + FormRequest)
 * 10-year perspective: Type enum (formative/summative/diagnostic) enables taxonomy reporting, boolean flags support learner experience customization, duration_minutes/max_attempts enable constraint enforcement, passing_score enables mastery tracking
 */
class StoreQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return in_array($user->role, ['enseignant', 'coordinateur', 'superAdmin', 'admin']);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'lesson_id' => 'nullable|exists:lessons,id',
            'matiere_id' => 'nullable|exists:matieres,id',
            'classe_id' => 'nullable|exists:classes,id',
            'type' => 'nullable|in:formative,summative,diagnostic',
            'duration_minutes' => 'nullable|integer|min:1',
            'max_attempts' => 'nullable|integer|min:1',
            'passing_score' => 'nullable|numeric|min:0|max:100',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_answers' => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'allow_review' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Le titre du quiz est requis.',
            'title.string' => 'Le titre doit être une chaîne de caractères.',
            'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',
            'lesson_id.exists' => 'La leçon sélectionnée n\'existe pas.',
            'matiere_id.exists' => 'La matière sélectionnée n\'existe pas.',
            'classe_id.exists' => 'La classe sélectionnée n\'existe pas.',
            'type.in' => 'Le type de quiz doit être parmi : formative, summative, diagnostic.',
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
