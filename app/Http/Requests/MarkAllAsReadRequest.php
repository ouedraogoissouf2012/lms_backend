<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates mark-all-as-read notification requests (POST /api/notifications/mark-all-as-read).
 *
 * ## Purpose
 * Authorize marking all notifications as read for the authenticated user.
 * No input validation required — only authentication check.
 * Extracted from inline checks in NotificationsController::markAllAsRead.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. Ownership is implicit — only updates notifications for authenticated user
 *
 * Controller ensures scope: Notification::where('user_id', $user->id)->whereNull('read_at')->update(...)
 */
final class MarkAllAsReadRequest extends FormRequest
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
