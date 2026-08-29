<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation creation request (POST /api/evaluations).
 *
 * ## Purpose
 * Create new evaluation with title, type, questions.
 * Only admin/teacher can create (coordinators excluded).
 *
 * ## Authorization Model
 * 1. User authenticated
 * 2. User is NOT coordinateur (explicit exclusion per controller logic)
 * 3. Evaluation created in user's institution (multi-tenant)
 *
 * If ANY check fails → 403 Forbidden
 *
 * ## Questions Structure
 * Optional nested array of questions, each with:
 * - question: text
 * - type: qcm, qcm_multiple, vrai_faux, reponse_courte, dissertation
 * - points: numeric
 * - options: array
 * - correct_answers: array
 */
final class StoreEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        // Coordinators cannot create evaluations
        if ($user->isCoordinator()) {
            return false;
        }

        // User must be admin or teacher
        if (!$user->isAdmin() && !$user->isTeacher()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Issue #124 : `klassci_enseignant_id` retiré de la validation —
        // désormais dérivé du token Sanctum côté controller (`$user->klassci_enseignant_id`)
        // et jamais lu du body. Empêche un enseignant de créer une évaluation
        // au nom d'un autre via mass-assignment.
        return [
            'klassci_matiere_id' => 'required|integer',
            'klassci_classe_id' => 'required|integer',
            'klassci_evaluation_id' => 'nullable|integer',
            'titre' => 'required|string|min:3|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'required|in:qcm,reponse_courte,dissertation,mixte',
            'date_evaluation' => 'nullable|date',
            'duree_minutes' => 'required|integer|min:1|max:1440',
            'coefficient' => 'nullable|numeric|min:0',
            'bareme' => 'nullable|numeric|min:0',
            'max_attempts' => 'nullable|integer|min:1',
            'allow_retake' => 'nullable|boolean',
            'is_online' => 'nullable|boolean',
            'shuffle_questions' => 'nullable|boolean',
            'show_results' => 'nullable|boolean',
            'questions' => 'nullable|array',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.type' => 'required|in:qcm,qcm_multiple,vrai_faux,reponse_courte,dissertation',
            'questions.*.points' => 'nullable|numeric|min:0',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answers' => 'nullable|array',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'klassci_matiere_id.required' => 'L\'ID matière KLASSCI est requis',
            'klassci_classe_id.required' => 'L\'ID classe KLASSCI est requis',
            'titre.required' => 'Le titre de l\'évaluation est requis',
            'titre.min' => 'Le titre doit contenir au moins 3 caractères',
            'titre.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'type.required' => 'Le type d\'évaluation est requis',
            'type.in' => 'Le type d\'évaluation est invalide',
            'duree_minutes.required' => 'La durée est requise',
            'duree_minutes.max' => 'La durée ne doit pas dépasser 1440 minutes',
            'questions.*.question.required' => 'Le texte de la question est requis',
            'questions.*.type.required' => 'Le type de question est requis',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('titre')) {
            $titre = $this->input('titre');
            $this->merge(['titre' => is_string($titre) ? trim($titre) : $titre]);
        }

        if ($this->has('description')) {
            $description = $this->input('description');
            $this->merge(['description' => is_string($description) ? trim($description) : $description]);
        }
    }
}
