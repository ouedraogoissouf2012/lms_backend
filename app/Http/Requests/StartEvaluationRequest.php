<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation start requests (POST /api/evaluations/{id}/start).
 *
 * ## Purpose
 * Validate student attempting to start evaluation.
 * Checks time window and question availability before starting.
 *
 * ## Authorization Model
 * Authenticated students only (auth:sanctum).
 * klassci_etudiant_id must match authenticated user's KLASSCI ID.
 *
 * ## 10-year Consideration
 * Time window validation managed by KLASSCI (start/end times).
 * Practice mode enabled for terminated evaluations (for revision).
 * If KLASSCI window logic changes, update docs and controller together.
 */
final class StartEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'klassci_etudiant_id' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return [
            'klassci_etudiant_id.required' => 'L\'ID étudiant KLASSCI est requis',
            'klassci_etudiant_id.integer' => 'L\'ID étudiant doit être un entier',
            'klassci_etudiant_id.min' => 'L\'ID étudiant doit être positif',
        ];
    }
}
