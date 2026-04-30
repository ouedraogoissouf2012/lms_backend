<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates start visio request (POST /api/lms/seances/{seanceId}/start-visio).
 *
 * ## Purpose
 * Authorize starting an active visioconférence session.
 * No input validation required — only authorization checks.
 * Extracted from inline role check in LMSDataController::startVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant or coordinateur (enforced by route middleware)
 * 3. Seance must exist with visio enabled (checked in controller)
 *
 * If ANY check fails → 401/403/404
 *
 * State checks (visio_enabled, visio_status) remain in controller — business logic.
 * Note: strictness of klassci_enseignant_id check was removed to allow:
 * - Replacement teachers
 * - Binôme teaching scenarios
 * - Coordinateurs who activate visio for any seance
 */
final class StartVisioRequest extends FormRequest
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
        if (!in_array($user->role, ['enseignant', 'teacher', 'coordinateur'])) {
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
