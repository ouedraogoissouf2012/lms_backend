<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

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
 * Les questions sont remplacées en bloc par ce même PUT (`questions`).
 * canBeEdited() prevents data loss (students already submitted).
 * Multi-tenant enforced via institution_id (handled inside the trait).
 *
 * ## C14 — la liste blanche, c'est la validation
 *
 * Le contrôleur ne transmet que `validated()` : un champ sans règle ici n'est
 * jamais écrit (`notes_published`, `is_locked`, `deadline_at`, les libellés…).
 * `is_published` est INTERDIT, quelle que soit sa valeur (`missing` : même
 * `false` ou `null`, qu'aucune route ne sait appliquer) : la publication passe
 * par `POST /publish`, qui refuse une évaluation sans question ou terminée. Le
 * refus porte son motif (`publish_via_dedicated_route`, ADR-906-01) : un
 * client qui l'envoyait croyait publier, et un silence lui mentirait.
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
            'is_published' => 'missing',
            'max_attempts' => 'sometimes|integer|min:1',
            'allow_retake' => 'sometimes|boolean',
            'is_online' => 'sometimes|boolean',
            'shuffle_questions' => 'sometimes|boolean',
            'show_results' => 'sometimes|boolean',
            // Mêmes règles qu'à la création (StoreEvaluationRequest).
            'type' => 'sometimes|in:qcm,reponse_courte,dissertation,mixte',
            // Exactement les règles de la création (StoreEvaluationRequest).
            // Leur `nullable` sur une colonne NOT NULL, et le barème nul, sont
            // traités ensemble dans les deux voies (C18).
            'coefficient' => 'nullable|numeric|min:0',
            'bareme' => 'nullable|numeric|min:0',
            'questions' => 'sometimes|array',
            'questions.*.question' => 'required|string|max:1000',
            'questions.*.type' => 'required|in:qcm,qcm_multiple,vrai_faux,reponse_courte,dissertation',
            'questions.*.points' => 'nullable|numeric|min:0',
            'questions.*.options' => 'nullable|array',
            'questions.*.correct_answers' => 'nullable|array',
            // Lue par le service : sans règle, `validated()` la retirerait.
            'questions.*.explanation' => 'nullable|string|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'titre.min' => 'Le titre doit contenir au moins 3 caractères',
            'titre.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères',
            'status.in' => 'Le statut est invalide',
            'is_published.missing' => 'Une évaluation se publie par l\'action « Publier », pas par sa modification.',
            'duree_minutes.max' => 'La durée ne doit pas dépasser 1440 minutes',
        ];
    }

    /**
     * Le refus de `is_published` porte son motif ; les autres erreurs gardent
     * le rendu 422 commun (`bootstrap/app.php`), qui rend une
     * `HttpResponseException` telle quelle (#906).
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('is_published')) {
            throw new HttpResponseException(response()->json([
                'success' => false,
                'message' => 'Une évaluation se publie par l\'action « Publier », pas par sa modification.',
                'reason' => 'publish_via_dedicated_route',
                'errors' => $validator->errors()->toArray(),
            ], 422));
        }

        parent::failedValidation($validator);
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
