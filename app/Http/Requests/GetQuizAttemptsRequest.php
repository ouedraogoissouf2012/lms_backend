<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * GetQuizAttemptsRequest - List attempts for a given quiz.
 *
 * Purpose: Authorize read access to the full list of submitted attempts.
 * Authorization: Quiz creator OR institution-scoped admin/coordinateur within
 *                the same tenant. Supradmin (platform manager) bypasses tenant
 *                isolation by design.
 *
 * Despite the trait's historical name (`ChecksForumAuthorization`), its logic
 * is generic — same shape, same defense-in-depth strategy as the Forum
 * FormRequests. Rename to a tenant-neutral name is tracked as a follow-up
 * cosmetic refactor (out of scope of #87).
 *
 * Fixes the HIGH IDOR identified in PR #86 audit (issue #87).
 *
 * @see app/Http/Requests/Concerns/ChecksForumAuthorization.php
 * @see .claude/specs/quiz-idor-get-attempts/design.md §2.3
 */
class GetQuizAttemptsRequest extends FormRequest
{
    use ChecksForumAuthorization;

    public function authorize(): bool
    {
        $quiz = $this->route('quiz');
        $quiz = $quiz instanceof \App\Models\Quiz ? $quiz : null;

        return $this->isForumActionAuthorized(
            ownerUserId: $quiz?->created_by,
            tenantInstitutionId: $quiz?->institution_id,
            user: Auth::user(),
            moderatorRoles: ['admin', 'administrateur', 'superAdmin', 'coordinateur'],
        );
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'user_id' => 'sometimes|integer|exists:users,id',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}
