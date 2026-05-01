<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * MarkPostAsSolutionRequest - Mark forum post as solution
 *
 * Purpose: Authorize solution marking with role-based access control
 * Authorization: Topic owner, coordinateurs, or admin
 * 10-year perspective: Solution marking enables knowledge distillation for forum discoverability
 */
class MarkPostAsSolutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');
        $user = Auth::user();
        $topic = $post->topic;

        return $topic->user_id === $user->id
            || $user->role === 'coordinateur'
            || $user->isAdmin();
    }

    public function rules(): array
    {
        return [];
    }
}
