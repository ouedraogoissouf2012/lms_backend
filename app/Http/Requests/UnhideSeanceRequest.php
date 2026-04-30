<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates unhide seance request (POST /api/lms/seances/{seanceId}/unhide).
 *
 * ## Purpose
 * Authorize unhiding a seance that was previously hidden by a student.
 * No input validation required — only authorization checks.
 * Extracted from inline role check in LMSDataController::unhideSeance.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is étudiant (enforced by route middleware role:etudiant)
 * 3. Seance exists (checked in controller, not validation concern)
 *
 * If ANY check fails → 401/403/404
 */
final class UnhideSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: Only students can manage hiding
        // (route middleware also enforces this, defense in depth)
        if ($user->role !== 'etudiant' && $user->role !== 'student') {
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
