<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation update request (PATCH /api/evaluations/{id}).
 *
 * ## Purpose
 * Update existing evaluation's metadata.
 * The `canBeEdited()` business check (no students have submitted yet) is
 * performed by the controller, not here.
 *
 * ## Authorization
 * Delegated to {@see \App\Http\Requests\Concerns\ChecksEvaluationOwnership::checkEvaluationOwnership()}.
 * Identical behavior across DeleteEvaluationRequest / PublishEvaluationRequest /
 * UpdateEvaluationRequest (issue #125 refactor).
 *
 * ## 10-year perspective
 * Questions can be modified via separate endpoint if needed.
 * canBeEdited() prevents data loss (students already submitted).
 * Multi-tenant enforced via institution_id (handled inside the trait).
 */
final class UpdateEvaluationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationOwnership();
    }

    public function rules(): array
    {
        return [
            'titre' => 'sometimes|string|min:3|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'sometimes|in:brouillon,planifiee,en_cours,terminee,annulee',
            'date_evaluation' => 'nullable|date',
            'duree_minutes' => 'sometimes|integer|min:1|max:1440',
            'is_published' => 'sometimes|boolean',
            'max_attempts' => 'sometimes|integer|min:1',
            'shuffle_questions' => 'sometimes|boolean',
            'show_results' => 'sometimes|boolean',
            'questions' => 'sometimes|array',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.type' => 'required|in:qcm,qcm_multiple,vrai_faux,reponse_courte,dissertation',
            'questions.*.points' => 'nullable|numeric|min:0',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answers' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'titre.min' => 'Le titre doit contenir au moins 3 caractères',
            'titre.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères',
            'status.in' => 'Le statut est invalide',
            'duree_minutes.max' => 'La durée ne doit pas dépasser 1440 minutes',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('titre')) {
            $this->merge(['titre' => trim($this->titre ?? '')]);
        }

        if ($this->has('description')) {
            $this->merge(['description' => trim($this->description ?? '')]);
        }
    }
}
