<?php

namespace App\Http\Requests;

use App\Rules\PositiveInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates lesson creation request.
 *
 * ## Purpose
 * Validates and authorizes POST /api/lessons requests. Ensures:
 * - User is authenticated + is teacher/coordinator
 * - Institution ownership (multi-tenant safety)
 * - Title/description are valid and sanitized
 * - Lesson type is allowed
 *
 * ## Why FormRequest (10-year perspective)
 * - Single source of truth: if validation rules change, update only here
 * - Testable independently: validation logic separated from controller
 * - Reusable: same rules can be used by update endpoint
 * - Secure: authorize() prevents unauthorized access before controller
 * - Maintainable: new devs see contract (input -> output) clearly
 *
 * ## Security Checks Applied
 * 1. Authorization: only teachers/coordinators can create
 * 2. Institution: classe must belong to user's institution
 * 3. Input validation: all fields type-checked + sanitized
 * 4. XSS prevention: description/content sanitized in middleware
 * 5. DOS prevention: title max length enforced
 */
final class StoreLessonRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = auth()->user();

        // Check 1: User must be authenticated (middleware ensures, but explicit)
        if (!$user) {
            return false;
        }

        // Check 2: User must be teacher or coordinator (not student)
        if (!$user->isTeacher() && !$user->isCoordinator()) {
            return false;
        }

        // Check 3: Multi-tenant safety - classe must belong to user's institution
        // Use exists() to avoid loading unnecessarily
        $classeExists = \App\Models\Classe::where('id', $this->classe_id)
            ->where('institution_id', $user->institution_id)
            ->exists();

        if (!$classeExists) {
            return false;
        }

        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'title' => [
                'required',
                'string',
                'min:3',
                'max:255',
            ],
            'description' => [
                'nullable',
                'string',
                'max:1000',
            ],
            'content' => [
                'nullable',
                'string',
                'max:10000',
            ],
            'type' => [
                'required',
                'string',
                'in:cours,tp,td,projet,autre',
            ],
            'classe_id' => [
                'required',
                new PositiveInteger(),
            ],
            'matiere_id' => [
                'nullable',
                new PositiveInteger(),
            ],
            'niveau_difficulte' => [
                'nullable',
                'string',
                'in:debutant,intermediaire,avance,expert',
            ],
            'duree_estimee_minutes' => [
                'nullable',
                'integer',
                'min:1',
                'max:480', // 8 hours max
            ],
            'status' => [
                'nullable',
                'string',
                'in:draft,published,archived',
            ],
        ];
    }

    /**
     * Get custom messages for validation errors.
     *
     * Human-friendly messages (not default Laravel messages).
     * Helps users understand what went wrong.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Le titre du cours est requis',
            'title.min' => 'Le titre doit contenir au moins 3 caractères',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'type.required' => 'Le type de cours est requis',
            'type.in' => 'Le type de cours doit être: cours, tp, td, projet ou autre',
            'classe_id.required' => 'Une classe doit être sélectionnée',
            'duree_estimee_minutes.max' => 'La durée estimée ne doit pas dépasser 480 minutes',
        ];
    }

    /**
     * Prepare data for validation.
     *
     * Called BEFORE validation rules are checked.
     * Used to trim whitespace and normalize inputs.
     *
     * Why trim here:
     * - Prevents " " (only spaces) being valid input
     * - Removes accidental leading/trailing whitespace
     * - Consistent data format in database
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'title' => trim($this->title ?? ''),
            'description' => trim($this->description ?? ''),
            'content' => trim($this->content ?? ''),
        ]);
    }
}
