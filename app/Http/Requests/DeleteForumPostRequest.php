<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * DeleteForumPostRequest - Delete forum post
 *
 * Purpose: Authorize forum post deletion with soft-delete preservation
 * Authorization: Owner or admin can delete
 * 10-year perspective: Soft deletes preserve audit trail for post history
 */
class DeleteForumPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');
        $user = Auth::user();

        return $post->user_id === $user->id || $user->isAdmin();
    }

    public function rules(): array
    {
        return [];
    }
}
