<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * UpdateForumTopicRequest - Update forum topic
 *
 * Purpose: Validate & authorize forum topic updates with ownership checks
 * Authorization: Owner or admin can update (not moderators per audit)
 * 10-year perspective: Preserves audit trail (is_edited flag + timestamps)
 */
class UpdateForumTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $user = Auth::user();

        return $topic->user_id === $user->id || $user->isAdmin();
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|min:3|max:255',
            'content' => 'sometimes|string|min:10|max:5000',
        ];
    }

    public function messages(): array
    {
        return [
            'title.min' => 'Le titre doit contenir au moins 3 caractères.',
            'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',
            'content.min' => 'Le contenu doit contenir au moins 10 caractères.',
            'content.max' => 'Le contenu ne peut pas dépasser 5000 caractères.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('title')) {
            $merge['title'] = trim($this->title);
        }
        if ($this->has('content')) {
            $merge['content'] = trim($this->content);
        }
        if (!empty($merge)) {
            $this->merge($merge);
        }
    }
}
