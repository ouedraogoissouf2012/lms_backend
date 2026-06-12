<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * StoreForumPostRequest - Create forum post/reply
 *
 * Purpose: Validate & authorize forum post creation with parent ownership validation
 * Authorization: Authenticated users can reply to open topics
 * 10-year perspective: parent_id chain enables threaded discussions for long-term engagement
 */
class StoreForumPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        $topic = $this->route('topic');
        $user = Auth::user();

        if ($user === null || ! $topic->isOpen()) {
            return false;
        }

        // Defense en profondeur (fix E2E #211 flow 5) : un user du tenant B
        // ne doit pas poster dans un topic du tenant A (binding non filtre
        // si le tenant n'est pas resolu).
        if ($user->institution_id !== null
            && $topic->institution_id !== $user->institution_id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'content' => 'required|string|min:3|max:2000',
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('forum_posts', 'id')
                    ->where('topic_id', $this->route('topic')->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'content.required' => 'Le contenu est obligatoire.',
            'content.min' => 'Le contenu doit contenir au moins 3 caractères.',
            'content.max' => 'Le contenu ne peut pas dépasser 2000 caractères.',
            'parent_id.exists' => 'Le post parent n\'existe pas ou n\'appartient pas à ce topic.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('content')) {
            $this->merge(['content' => trim($this->content)]);
        }
    }
}
