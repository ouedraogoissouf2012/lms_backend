<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates delete seance request (DELETE /api/lms/seances/{seanceId}).
 *
 * ## Purpose
 * Authorize deletion of a seance. No input validation required.
 * Extracted from inline role/ownership checks in LMSDataController::deleteSeance.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant/coordinateur/superAdmin (route middleware enforces base roles)
 * 3. For enseignants only: seance must belong to user (klassci_enseignant_id matches)
 * 4. For coordinateurs/admins: can delete any seance
 *
 * State checks (visio_active) remain in controller — business logic, not validation.
 *
 * If ANY check fails → 403/404
 */
final class DeleteSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: User must be enseignant/coordinateur/superAdmin
        // (route middleware also enforces this, defense in depth)
        if (!($user->isTeacher() || $user->isCoordinator() || $user->isAdmin())) {
            return false;
        }

        // Check 3: Seance must exist (try both local id and klassci_seance_id)
        $seanceId = $this->route('seanceId');
        $seance = \App\Models\Seance::find($seanceId);
        if (!$seance) {
            $seance = \App\Models\Seance::where('klassci_seance_id', $seanceId)->first();
        }

        if (!$seance) {
            return false;
        }

        // Check 4: For enseignants only - verify ownership (klassci_enseignant_id match)
        // Coordinateurs and admins can delete any seance
        if ($user->isTeacher()) {
            if ($seance->klassci_enseignant_id && $seance->klassci_enseignant_id !== $user->klassci_id) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        // No input validation for DELETE
        return [];
    }
}
