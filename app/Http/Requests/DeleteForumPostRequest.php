<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * DeleteForumPostRequest - Delete forum post
 *
 * Purpose: Authorize forum post deletion with ownership + tenant isolation.
 * Authorization: Post owner OR institution-scoped admin within the same tenant.
 *                Supradmin (platform manager) bypasses tenant isolation by design.
 * 10-year perspective: Soft deletes preserve audit trail for post history.
 */
class DeleteForumPostRequest extends FormRequest
{
    use ChecksForumAuthorization;

    public function authorize(): bool
    {
        $post = $this->route('post');
        $post = $post instanceof \App\Models\ForumPost ? $post : null;

        return $this->isForumActionAuthorized(
            ownerUserId: $post?->user_id,
            tenantInstitutionId: $post?->institution_id,
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
