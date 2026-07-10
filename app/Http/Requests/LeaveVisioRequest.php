<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates leave visio request (POST /api/lms/seances/{seanceId}/leave).
 *
 * ## Purpose
 * Authorize leaving an active visioconférence session.
 * No input validation required — only authorization checks.
 * Extracted from inline checks in LMSDataController::leaveVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. Any authenticated user can leave (no role restriction)
 * 3. Rate limit applied: throttle:300,1 (route middleware)
 *
 * If ANY check fails → 401/404
 *
 * State check (active attendance record must exist) remains in controller.
 * Marks participant as disconnected and records duration.
 */
final class LeaveVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check: User must be authenticated
        // Any authenticated user can leave their active attendance
        return auth()->check();
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
