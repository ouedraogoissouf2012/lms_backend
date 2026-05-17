<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validates create notification requests (POST /api/admin/notifications/create).
 *
 * ## Purpose
 * Admin/coordinator manually create and send custom notifications to specific users.
 * Prevents unauthorized bulk notifications and cross-tenant notification injection.
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
 * ## Authorization model (issue #98 fix)
 *
 * 1. **Caller authenticated** (via `auth:sanctum` middleware).
 * 2. **Caller role** is one of `coordinateur`, `superAdmin`, `supradmin` (defense in
 *    depth: route middleware `role:` enforces this too).
 * 3. **Tenant isolation**: unless caller is `supradmin` (platform manager, cross-tenant
 *    by design — cf. `EnsureRole::userHasRole()` L107-108), `targetUser.institution_id`
 *    MUST equal `caller.institution_id`. Otherwise the request is rejected (403).
 *
 * Without check 3, a coordinator of institution A could send notifications to users of
 * institution B (MEDIUM IDOR finding from PR #97 spec-security audit).
 *
 * Side note: the `supradmin` role was previously missing from the route middleware
 * `role:coordinateur,superAdmin`. Added in routes/api.php L735 to make the bypass
 * actually reachable, otherwise REQ-2/REQ-5 of the spec would be dead code.
 *
 * @see app/Http/Controllers/API/NotificationsController.php (caller)
 * @see app/Http/Middleware/EnsureRole.php L107-108 (supradmin semantics)
 * @see .claude/specs/notifications-cross-tenant/design.md §2.1
 */
final class CreateNotificationRequest extends FormRequest
{
    /**
     * Authorize: role + tenant isolation (supradmin bypass).
     */
    public function authorize(): bool
    {
        $caller = Auth::user();
        if (!$caller instanceof User) {
            return false;
        }

        // Role check (defense in depth above route middleware)
        if (!in_array($caller->role, ['coordinateur', 'superAdmin', 'supradmin'], true)) {
            return false;
        }

        // Supradmin bypass tenant isolation (platform manager)
        if ($caller->role === 'supradmin') {
            return true;
        }

        // Tenant check: target user must belong to caller's institution
        $targetUserId = $this->input('user_id');
        if (!is_numeric($targetUserId)) {
            return false;
        }

        $targetUser = User::find((int) $targetUserId);
        if ($targetUser === null) {
            return false;
        }

        return $targetUser->institution_id === $caller->institution_id;
    }

    /**
     * Get validation rules for notification creation.
     *
     * @return array<string, array<int, string>>
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
     * @return array<string, string>
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
