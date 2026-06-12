<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * UpdateForumTopicRequest - Update forum topic
 *
 * Purpose: Validate & authorize forum topic updates with ownership + tenant isolation.
 * Authorization: Topic owner OR institution-scoped admin within the same tenant.
 *                Supradmin (platform manager) bypasses tenant isolation by design.
 * 10-year perspective: Preserves audit trail (is_edited flag + timestamps).
 */
class UpdateForumTopicRequest extends FormRequest
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
            // Fix #212 : `$this->content` = corps HTTP brut, pas l'input.
            $merge['content'] = trim((string) $this->input('content'));
        }
        if (!empty($merge)) {
            $this->merge($merge);
        }
    }
}
