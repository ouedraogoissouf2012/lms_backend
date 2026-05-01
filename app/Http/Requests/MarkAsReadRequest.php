<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates mark-as-read notification requests (POST /api/notifications/{id}/mark-as-read).
 *
 * ## Purpose
 * Mark a single notification as read for authenticated user.
 * Prevents unauthed users from manipulating notification state.
 *
 * ## Authorization Model
 * Authenticated users only.
 * Ownership verified in controller via SQL scope: Notification::where('user_id', $user->id)->findOrFail($id)
 * Returns 404 if notification doesn't belong to user (prevents cross-user data access).
 *
 * ## No Input Fields
 * POST body optional — only route parameter {id} required.
 * Notification ID presence checked by controller via findOrFail().
 *
 * ## 10-year consideration
 * Delegation of ownership check to controller SQL scope is acceptable pattern:
 * - Simpler FormRequest (no N+1 risk)
 * - Controller layer handles data access control
 * - SQL findOrFail() provides natural access control boundary
 *
 * Performance: Zero database queries in authorize() (auth check only, no lookup).
 */
final class MarkAsReadRequest extends FormRequest
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
     * Get validation rules for notification mark-as-read.
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
