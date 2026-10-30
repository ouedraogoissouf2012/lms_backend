<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates activate visio request (POST /api/lms/seances/{seanceId}/activate-visio).
 *
 * ## Purpose
 * Authorize and validate the request to enable visioconférence for a seance.
 * No input validation required — only authorization checks.
 * Extracted from inline role check in LMSDataController::activateVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant or coordinateur (enforced by route middleware)
 * 3. Seance must exist in KLASSCI (checked by the service via API call)
 *
 * `authorize()` answers « may this user do this? », not « will the upstream
 * call work? ». The KLASSCI token is a precondition of the WORK : a missing
 * token is answered by `VisioActivationService::activate()`
 * with the motivated 401 of #806 — no longer a generic 403 from here.
 *
 * If ANY check fails → 401/403/404
 *
 * State check (seance already enabled) remains in controller — business logic.
 */
final class ActivateVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: User must be enseignant or coordinateur
        // (route middleware also enforces this, defense in depth)
        if (!($user->isTeacher() || $user->isCoordinator())) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // No input validation for POST with no body
        return [];
    }
}
