<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * UpdateForumPostRequest - Update forum post
 *
 * Purpose: Validate & authorize forum post updates with ownership checks
 * Authorization: Owner or admin can update
 * 10-year perspective: is_edited flag tracks modification history for transparency
 */
class UpdateForumPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $post = $this->route('post');
        $user = Auth::user();

        return $post->user_id === $user->id || $user->isAdmin();
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
