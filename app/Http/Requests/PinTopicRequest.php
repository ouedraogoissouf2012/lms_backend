<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * PinTopicRequest - Pin forum topic
 *
 * Purpose: Authorize topic pinning with ownership + tenant isolation.
 * Authorization: Topic owner OR institution-scoped admin/coordinateur within
 *                the same tenant. Supradmin bypasses tenant isolation.
 * 10-year perspective: Pinned topics float to top, enabling visibility for
 *                      important discussions.
 */
class PinTopicRequest extends FormRequest
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
