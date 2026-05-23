<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates deactivate visio request (POST /api/lms/seances/{seanceId}/deactivate-visio).
 *
 * ## Purpose
 * Authorize disabling visioconférence for a seance.
 * No input validation required — only authorization checks.
 * Extracted from inline role/ownership checks in LMSDataController::deactivateVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant only (enforced by route middleware role:enseignant)
 * 3. User must own the seance (klassci_enseignant_id matches user.klassci_id)
 * 4. User has KLASSCI token (implicit, already checked by route)
 *
 * If ANY check fails → 401/403/404
 *
 * State checks (visio_enabled) remain in controller — business logic.
 */
final class DeactivateVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: Only enseignants can deactivate
        // (route middleware also enforces this, defense in depth)
        if (!$user->isTeacher()) {
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

        // Check 4: Teacher must own the seance (klassci_enseignant_id match)
        if ($seance->klassci_enseignant_id && $seance->klassci_enseignant_id !== $user->klassci_id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        // No input validation for POST with no body
        return [];
    }
}
