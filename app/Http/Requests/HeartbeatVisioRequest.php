<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates heartbeat visio request (POST /api/lms/seances/{seanceId}/heartbeat).
 *
 * ## Purpose
 * Authorize sending activity ping from an active visioconférence session.
 * No input validation required — only authorization checks.
 * Extracted from inline checks in LMSDataController::heartbeatVisio.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. Any authenticated user can send heartbeat (no role restriction)
 * 3. Rate limit applied: throttle:10000,1 (route middleware — very permissive for frequent pings)
 *
 * If ANY check fails → 401/404
 *
 * State check (active attendance record must exist) remains in controller.
 * Updates last_seen_at to track continuous participation.
 * Frequent calls (every few seconds) expected for real-time presence tracking.
 */
final class HeartbeatVisioRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check: User must be authenticated
        // Any authenticated user can send heartbeat for their session
        return auth()->check();
    }

    public function rules(): array
    {
        // No input validation for POST with no body
        return [];
    }
}
