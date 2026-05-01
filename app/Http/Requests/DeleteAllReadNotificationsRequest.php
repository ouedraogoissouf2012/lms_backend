<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates delete-all-read notifications requests (DELETE /api/notifications/read/all).
 *
 * ## Purpose
 * Authorize deletion of all read notifications for the authenticated user.
 * No input validation required — only authentication check.
 * Extracted from inline checks in NotificationsController::deleteAllRead.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. Ownership is implicit — only deletes read notifications for authenticated user
 *
 * Controller ensures scope: Notification::where('user_id', $user->id)->whereNotNull('read_at')->delete()
 */
final class DeleteAllReadNotificationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check: User must be authenticated
        return auth()->check();
    }

    public function rules(): array
    {
        // No input validation for DELETE with no body
        return [];
    }
}
