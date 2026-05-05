<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates token refresh requests (POST /api/auth/refresh).
 *
 * ## Purpose
 * Revoke old access token and issue new one.
 * No input validation required — token validation via middleware.
 *
 * ## Authorization Model
 * Authenticated users only (auth:sanctum middleware).
 * Ownership: implicit (refresh is user's own token).
 *
 * ## 10-year Consideration
 * Single-use tokens prevent token reuse attacks.
 * Old token revoked immediately.
 */
final class RefreshTokenRequest extends FormRequest
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
