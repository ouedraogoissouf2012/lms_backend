<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates delete notification requests (DELETE /api/notifications/{id}).
 *
 * ## Purpose
 * Delete a single notification for authenticated user.
 * Prevents unauthed users from removing notifications.
 *
 * ## Authorization Model
 * Authenticated users only.
 * Ownership verified in controller via SQL scope: Notification::where('user_id', $user->id)->findOrFail($id)
 * Returns 404 if notification doesn't belong to user (prevents cross-user data deletion).
 *
 * ## No Input Fields
 * DELETE request with no body — only route parameter {id} required.
 * Notification ID presence and ownership checked via controller findOrFail().
 *
 * ## Deletion Behavior
 * Soft delete via Model::delete() preserves historical audit trail.
 * User can't permanently delete their own notifications (prevents data loss).
 *
 * ## 10-year consideration
 * SQL scope in controller provides natural access control boundary.
 * Delegation acceptable for single-resource operations (not bulk).
 * Performance: Zero database queries in authorize() (auth check only, no lookup).
 *
 * Cache invalidation: Controller invalidates notification caches on success.
 */
final class DeleteNotificationRequest extends FormRequest
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
     * Get validation rules for notification deletion.
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
