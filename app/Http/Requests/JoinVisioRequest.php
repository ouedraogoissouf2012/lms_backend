<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates join visio request (POST /api/lms/seances/{seanceId}/join).
 *
 * ## Purpose
 * Authorize joining an active visioconférence session.
 * No input validation required — only authorization checks.
 * Extracted from inline checks in LMSDataController::joinVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. Any authenticated user can join (no role restriction)
 * 3. Seance must exist (checked in controller)
 * 4. Rate limit applied: throttle:300,1 (route middleware)
 *
 * If ANY check fails → 401/404
 *
 * State checks (visio_status === 'active', visio_room_id exists) remain in controller.
 * Role handling:
 * - Coordinateurs marked as observers (is_observer=true)
 * - Teachers and students tracked normally
 * - Attendance record created regardless of role
 */
final class JoinVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check: User must be authenticated
        // Any authenticated user can join a visio (no role restriction)
        return auth()->check();
    }

    public function rules(): array
    {
        // No input validation for POST with no body
        return [];
    }
}
