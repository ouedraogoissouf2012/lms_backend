<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates hide seance request (POST /api/lms/seances/{seanceId}/hide).
 *
 * ## Purpose
 * Authorize hiding a seance from a student's personal view.
 * No input validation required — only authorization checks.
 * Extracted from inline role check in LMSDataController::hideSeance.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is étudiant (enforced by route middleware role:etudiant)
 * 3. Seance exists (checked in controller, not validation concern)
 *
 * If ANY check fails → 401/403/404
 */
final class HideSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: Only students can hide seances
        // (route middleware also enforces this, defense in depth)
        if (!$user->isStudent()) {
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
