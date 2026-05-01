<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * DeleteForumTopicRequest - Delete forum topic
 *
 * Purpose: Authorize forum topic deletion with soft-delete preservation
 * Authorization: Owner or admin can delete
 * 10-year perspective: Uses soft deletes, preserves audit trail for compliance
 */
class DeleteForumTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $user = Auth::user();

        return $topic->user_id === $user->id || $user->isAdmin();
    }

    public function rules(): array
    {
        return [];
    }
}
