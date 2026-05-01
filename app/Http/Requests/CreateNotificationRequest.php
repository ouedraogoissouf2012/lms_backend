<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates create notification requests (POST /api/admin/notifications/create).
 *
 * ## Purpose
 * Admin/coordinator manually create and send custom notifications to specific users.
 * Prevents unauthorized bulk notifications.
 * Validates notification content (title, message, URL, type).
 *
 * ## Required Fields
 * - user_id: Target recipient (must exist in database)
 * - title: Notification title (max 255 chars)
 * - message: Notification message body (required, no length limit)
 *
 * ## Optional Fields
 * - type: Notification type enum (info, success, warning, danger) — defaults to 'info'
 * - action_url: Call-to-action URL (must be valid URL format if provided)
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User has role coordinateur OR superAdmin (enforced by route middleware + this request)
 * 3. Defense in depth: Authorization checked twice (middleware + FormRequest authorize())
 *
 * If ANY check fails → 401/403
 *
 * ## 10-year consideration
 * Role enum (coordinateur, superAdmin) must match User model.
 * If new admin roles added, update rules and authorize() together.
 * Type enum (info,success,warning,danger) must match Notification model + frontend UI.
 *
 * Performance: exists:users,id validation performs 1 database query (acceptable for single insert).
 */
final class CreateNotificationRequest extends FormRequest
{
    /**
     * Verify user is admin/coordinator.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Defense in depth: Route middleware enforces role, this request validates too
        return in_array($user->role, ['coordinateur', 'superAdmin']);
    }

    /**
     * Get validation rules for notification creation.
     *
     * @return array
     */
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

    /**
     * Custom error messages in French.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'user_id.required' => 'L\'utilisateur destinataire est requis',
            'user_id.integer' => 'L\'identifiant utilisateur doit être un nombre entier',
            'user_id.exists' => 'L\'utilisateur sélectionné n\'existe pas',
            'title.required' => 'Le titre de la notification est requis',
            'title.string' => 'Le titre doit être du texte',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'message.required' => 'Le message de la notification est requis',
            'message.string' => 'Le message doit être du texte',
            'type.string' => 'Le type doit être du texte',
            'type.in' => 'Le type de notification est invalide (info, success, warning, danger)',
            'action_url.url' => 'L\'URL d\'action doit être un URL valide',
        ];
    }
}
