<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * CloseTopicRequest - Close forum topic
 *
 * Purpose: Authorize topic closure with ownership + tenant isolation.
 * Authorization: Topic owner OR institution-scoped admin/coordinateur within
 *                the same tenant. Supradmin bypasses tenant isolation.
 * 10-year perspective: Closed topics prevent post-discussion spam while
 *                      preserving knowledge.
 */
class CloseTopicRequest extends FormRequest
{
    use ChecksForumAuthorization;

    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $topic = $topic instanceof \App\Models\ForumTopic ? $topic : null;

        return $this->isForumActionAuthorized(
            ownerUserId: $topic?->user_id,
            tenantInstitutionId: $topic?->institution_id,
            user: Auth::user(),
            moderatorRoles: ['admin', 'administrateur', 'superAdmin', 'coordinateur'],
        );
    }

    public function rules(): array
    {
        return [];
    }
}
