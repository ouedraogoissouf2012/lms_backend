<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates end visio request (POST /api/lms/seances/{seanceId}/end-visio).
 *
 * ## Purpose
 * Authorize ending an active visioconférence session.
 * No input validation required — only authorization checks.
 * Extracted from inline role check in LMSDataController::endVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is enseignant or coordinateur (enforced by route middleware)
 * 3. Seance must exist (checked in controller)
 *
 * If ANY check fails → 401/403/404
 *
 * State check (visio_status === 'active') remains in controller — business logic.
 * Only validates that user has authorization to end any visio session.
 */
final class EndVisioRequest extends FormRequest
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
