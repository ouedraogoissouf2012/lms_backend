<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates create notification requests (POST /api/admin/notifications/create).
 *
 * ## Purpose
 * Authorize admin/coordinator to create and send custom notifications to users.
 * Validate notification payload (recipient, title, message, etc.).
 * Extracted from inline validation in NotificationsController::create.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User has role coordinateur or superAdmin (enforced by route middleware)
 * 3. This request validates the authorization is enforced (defense-in-depth)
 *
 * If ANY check fails → 401/403
 */
final class CreateNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: User must be coordinateur or superAdmin
        // (route middleware also enforces this, defense in depth)
        if (!in_array($user->role, ['coordinateur', 'superAdmin'])) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'title' => [
                'required',
                'string',
                'max:255',
            ],
            'message' => [
                'required',
                'string',
            ],
            'type' => [
                'nullable',
                'string',
                'in:info,success,warning,danger',
            ],
            'action_url' => [
                'nullable',
                'url',
            ],
        ];
    }
}
