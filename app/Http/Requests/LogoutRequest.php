<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates logout requests (POST /api/auth/logout).
 *
 * ## Purpose
 * Revoke current access token and end user session.
 * No input validation required — token validation via middleware.
 *
 * ## Authorization Model
 * Authenticated users only (auth:sanctum middleware).
 * Ownership: implicit (token revocation is user's own token).
 *
 * ## 10-year Consideration
 * Soft-revoke via token deletion preserves audit trail.
 * No hard-delete of user sessions.
 */
final class LogoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [];
    }
}
