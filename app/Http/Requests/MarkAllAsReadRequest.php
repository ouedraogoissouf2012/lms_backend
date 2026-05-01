<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates mark-all-as-read notification requests (POST /api/notifications/mark-all-as-read).
 *
 * ## Purpose
 * Mark all unread notifications as read for authenticated user.
 * Prevents unauthed users from bulk-updating notification state.
 *
 * ## Authorization Model
 * Authenticated users only.
 * Ownership is implicit in controller: Notification::where('user_id', $user->id)->whereNull('read_at')->update(...)
 * Ensures only user's own notifications are updated.
 *
 * ## No Input Fields
 * POST body optional — operates on authenticated user's unread notifications only.
 * No parameters passed; scope determined entirely by user context.
 *
 * ## 10-year consideration
 * Bulk operation on filtered dataset (user_id + unread state).
 * SQL scope in controller prevents cross-user updates.
 * Performance: Zero database queries in authorize() (auth check only, no lookup).
 *
 * Cache invalidation: Controller invalidates all notification caches on success.
 */
final class MarkAllAsReadRequest extends FormRequest
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
     * Get validation rules for mark-all-as-read.
     *
     * @return array
     */
    public function rules(): array
    {
        // No input validation for POST request with no body
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
