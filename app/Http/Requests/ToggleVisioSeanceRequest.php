<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates visio toggle request (POST /api/lms/seances/{seanceId}/toggle-visio).
 *
 * ## Purpose
 * Authorize and validate the request to enable/disable visioconférence for a seance.
 * Extracted from inline Validator::make in LMSDataController::toggleVisioSeance.
 *
 * ## Authorization Model
 * 1. User authenticated
 * 2. User is coordinateur or superAdmin (enforced by route middleware role:coordinateur,superAdmin)
 * 3. User has KLASSCI token (needed for subsequent API calls)
 * 4. Input validation: enabled (boolean required), visio_type (nullable enum)
 *
 * If ANY check fails → 401/403/422
 */
final class ToggleVisioSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: User must be coordinateur or superAdmin
        // (route middleware also enforces this, defense in depth)
        if (!($user->isCoordinator() || $user->isAdmin())) {
            return false;
        }

        // Check 3: User must have klassci token (needed for KLASSCI API calls in controller)
        if (!$user->klassci_token) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'enabled' => 'required|boolean',
            'visio_type' => 'nullable|in:jitsi,zoom,teams,bbb',
        ];
    }

    public function messages(): array
    {
        return [
            'enabled.required' => 'Le champ enabled est obligatoire.',
            'enabled.boolean' => 'Le champ enabled doit être un booléen (true/false).',
            'visio_type.in' => 'Le type de visio doit être l\'un de: jitsi, zoom, teams, bbb.',
        ];
    }
}
