<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksForumAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * UpdateForumPostRequest - Update forum post
 *
 * Purpose: Validate & authorize forum post updates with ownership + tenant isolation.
 * Authorization: Post owner OR institution-scoped admin within the same tenant.
 *                Supradmin (platform manager) bypasses tenant isolation by design.
 * 10-year perspective: is_edited flag tracks modification history for transparency.
 */
class UpdateForumPostRequest extends FormRequest
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

    public function rules(): array
    {
        return [
            'content' => 'required|string|min:3|max:2000',
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Le contenu est obligatoire.',
            'content.min' => 'Le contenu doit contenir au moins 3 caractères.',
            'content.max' => 'Le contenu ne peut pas dépasser 2000 caractères.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('content')) {
            $this->merge(['content' => trim($this->content)]);
        }
    }
}
