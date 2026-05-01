<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates mark-as-read notification requests (POST /api/notifications/{id}/mark-as-read).
 *
 * ## Purpose
 * Authorize marking a single notification as read.
 * No input validation required — only authentication check.
 * Extracted from inline checks in NotificationsController::markAsRead.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. Notification must exist and belong to user (checked in controller via SQL scope)
 *
 * Ownership is verified in controller via: Notification::where('user_id', $user->id)->findOrFail($id)
 * This returns 404 if the notification doesn't belong to the user.
 */
final class MarkAsReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check: User must be authenticated
        return auth()->check();
    }

    public function rules(): array
    {
        // No input validation for POST with no body
        return [];
    }
}
