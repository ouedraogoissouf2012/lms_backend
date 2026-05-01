<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates delete-all-read notifications requests (DELETE /api/notifications/read/all).
 *
 * ## Purpose
 * Delete all read notifications for authenticated user.
 * Prevents unauthed users from bulk-deleting notifications.
 * Prevents accidental deletion of unread notifications.
 *
 * ## Authorization Model
 * Authenticated users only.
 * Ownership is implicit in controller: Notification::where('user_id', $user->id)->whereNotNull('read_at')->delete()
 * Ensures only user's own READ notifications are deleted.
 * Unread notifications remain untouched (filter: whereNotNull('read_at')).
 *
 * ## No Input Fields
 * DELETE request with no body — operates on authenticated user's read notifications only.
 * No parameters passed; scope determined entirely by user context.
 *
 * ## Deletion Behavior
 * Soft delete via Model::delete() preserves historical audit trail.
 * Only deletes notifications with read_at timestamp (prevents unread data loss).
 * Safe cleanup operation.
 *
 * ## 10-year consideration
 * Bulk operation with filtered dataset (user_id + read state).
 * SQL scope in controller prevents cross-user deletion.
 * Performance: Zero database queries in authorize() (auth check only, no lookup).
 *
 * Cache invalidation: Controller invalidates all notification caches on success.
 */
final class DeleteAllReadNotificationsRequest extends FormRequest
{
    /**
     * Verify user is authenticated.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * Get validation rules for delete-all-read.
     *
     * @return array
     */
    public function rules(): array
    {
        // No input validation for DELETE request with no body
        return [];
    }

    /**
     * Custom error messages.
     *
     * @return array
     */
    public function messages(): array
    {
        return [];
    }
}
