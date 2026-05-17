<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use App\Models\QuizAttempt;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * ShowAttemptRequest - Retrieve quiz attempt details.
 *
 * Purpose: Authorize read access to a single attempt's full details
 *          (questions + corrected answers + score).
 * Authorization: Attempt owner OR creator of the parent quiz OR
 *                institution-scoped admin within the same tenant.
 *                Supradmin bypasses tenant isolation by design.
 *
 * The attempt and the quiz parent may have different authors. We therefore
 * use a 2-call short-circuit on the trait — first as attempt-owner candidate,
 * then as quiz-creator candidate. Both calls share the same tenant.
 *
 * Note: `coordinateur` is intentionally NOT in `moderatorRoles` for this
 * endpoint. Coordinators supervise (list/stats) but do not view per-student
 * answer details. If product decides otherwise, add `'coordinateur'` to the
 * arrays below (REF: design.md §2.4, R1).
 *
 * Fixes the MEDIUM IDOR (admin cross-tenant via `isAdmin()` ambiguity)
 * identified in the same #87 fix scope. Replaces the inline check that used
 * to live in `QuizController::showAttempt()`.
 *
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php
 * @see .claude/specs/quiz-idor-get-attempts/design.md §2.4
 */
class ShowAttemptRequest extends FormRequest
{
    use ChecksForumAuthorization;

    public function authorize(): bool
    {
        $id = $this->route('id');
        if (!is_numeric($id)) {
            return false;
        }

        $attempt = QuizAttempt::with('quiz')->find((int) $id);
        if ($attempt === null) {
            return false;
        }

        $user = Auth::user();
        $moderatorRoles = ['admin', 'administrateur', 'superAdmin'];

        // Path 1: caller owns the attempt OR is an intra-tenant admin.
        if ($this->isForumActionAuthorized(
            ownerUserId: $attempt->user_id,
            tenantInstitutionId: $attempt->institution_id,
            user: $user,
            moderatorRoles: $moderatorRoles,
        )) {
            return true;
        }

        // Path 2: caller is the creator of the parent quiz (intra-tenant).
        // The supradmin and admin branches of the trait are redundant here
        // but harmless (short-circuit already returned true above in those cases).
        return $this->isForumActionAuthorized(
            ownerUserId: $attempt->quiz?->created_by,
            tenantInstitutionId: $attempt->institution_id,
            user: $user,
            moderatorRoles: $moderatorRoles,
        );
    }

    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }
}
