<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * MarkPostAsSolutionRequest - Mark forum post as solution
 *
 * Purpose: Authorize solution marking with topic-owner check + tenant isolation.
 *          Note: ownership is checked against the TOPIC owner, not the post owner.
 * Authorization: Topic owner OR institution-scoped admin/coordinateur within
 *                the same tenant. Supradmin bypasses tenant isolation.
 * 10-year perspective: Solution marking enables knowledge distillation for
 *                      forum discoverability.
 */
class MarkPostAsSolutionRequest extends FormRequest
{
    use ChecksForumAuthorization;

    public function authorize(): bool
    {
        $post = $this->route('post');
        $post = $post instanceof \App\Models\ForumPost ? $post : null;
        $topic = $post?->topic;

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
