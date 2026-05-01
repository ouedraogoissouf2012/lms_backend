<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation delete request (DELETE /api/evaluations/{id}).
 *
 * ## Purpose
 * Authorize deletion of evaluation.
 * Can only delete if no students have submitted yet (canBeEdited check).
 *
 * ## Authorization Model
 * 1. User authenticated
 * 2. User is NOT coordinateur
 * 3. Evaluation exists and belongs to user's institution
 * 4. Evaluation.canBeEdited() == true (checked by controller)
 */
final class DeleteEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        // Coordinators cannot delete evaluations
        if ($user->role === 'coordinateur') {
            return false;
        }

        // Evaluation must exist and belong to user's institution
        $evaluation = \App\Models\Evaluation::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (!$evaluation) {
            return false;
        }

        // Check ownership: only the assigned enseignant can modify
        $userKlassciEnseignantId = data_get($user->klassci_data, 'enseignant_id');
        if (!$user->isAdmin() && $evaluation->klassci_enseignant_id !== $userKlassciEnseignantId) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
