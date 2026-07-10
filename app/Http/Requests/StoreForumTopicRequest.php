<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * StoreForumTopicRequest - Create forum topic
 *
 * Purpose: Validate & authorize forum topic creation with multi-tenant safety
 * Authorization: Authenticated users can create topics in their institution
 * 10-year perspective: Topics grouped by matiere/classe/lesson enable long-term searchability
 */
class StoreForumTopicRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Auth::check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string|min:3|max:255',
            'content' => 'required|string|min:10|max:5000',
            'matiere_id' => 'nullable|integer|exists:matieres,id',
            'classe_id' => 'nullable|integer|exists:classes,id',
            'lesson_id' => 'nullable|integer|exists:lessons,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Le titre est obligatoire.',
            'title.min' => 'Le titre doit contenir au moins 3 caractères.',
            'title.max' => 'Le titre ne peut pas dépasser 255 caractères.',
            'content.required' => 'Le contenu est obligatoire.',
            'content.min' => 'Le contenu doit contenir au moins 10 caractères.',
            'content.max' => 'Le contenu ne peut pas dépasser 5000 caractères.',
            'matiere_id.exists' => 'La matière sélectionnée n\'existe pas.',
            'classe_id.exists' => 'La classe sélectionnée n\'existe pas.',
            'lesson_id.exists' => 'Le cours sélectionné n\'existe pas.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('title')) {
            $title = $this->input('title');
            $merge['title'] = is_string($title) ? trim($title) : $title;
        }
        if ($this->has('content')) {
            // Fix #212 : `$this->content` = corps HTTP brut, pas l'input.
            $content = $this->input('content');
            $merge['content'] = is_string($content) ? trim($content) : $content;
        }
        if (!empty($merge)) {
            $this->merge($merge);
        }
    }
}
