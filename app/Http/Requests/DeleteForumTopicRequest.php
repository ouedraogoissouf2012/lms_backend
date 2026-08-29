<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * DeleteForumTopicRequest - Delete forum topic
 *
 * Purpose: Authorize forum topic deletion with ownership + tenant isolation.
 * Authorization: Topic owner OR institution-scoped admin within the same tenant.
 *                Supradmin (platform manager) bypasses tenant isolation by design.
 * 10-year perspective: Uses soft deletes, preserves audit trail for compliance.
 */
class DeleteForumTopicRequest extends FormRequest
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
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
