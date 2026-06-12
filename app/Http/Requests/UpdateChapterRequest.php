<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates chapter update request (PATCH /api/chapters/{id}).
 *
 * ## Purpose
 * Update chapter metadata (title, description, content, URLs).
 * Chapter belongs to lesson → authorize via lesson ownership.
 *
 * ## Authorization Model
 * Only lesson owner (teacher) or admin can update:
 * 1. User authenticated
 * 2. User is teacher/coordinator/admin
 * 3. Chapter's lesson owner == user OR is admin
 * 4. Chapter belongs to user's institution (multi-tenant)
 *
 * If ANY check fails → 403 Forbidden
 */
final class UpdateChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        // Only teachers/coordinators/admins can update
        if (!$user->isTeacher() && !$user->isCoordinator() && !$user->isAdmin()) {
            return false;
        }

        // Chapter must exist and belong to user's institution
        $chapter = \App\Models\Chapter::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (!$chapter) {
            return false;
        }

        // Get the lesson this chapter belongs to
        $lesson = \App\Models\Lesson::find($chapter->lesson_id);
        if (!$lesson || $lesson->institution_id !== $user->institution_id) {
            return false;
        }

        // User must own the lesson OR be admin
        if (!$user->isAdmin() && $lesson->enseignant_id !== $user->id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|min:3|max:255',
            'description' => 'nullable|string|max:1000',
            'content' => 'nullable|string|max:10000',
            'video_url' => 'nullable|url',
            'external_link' => 'nullable|url',
            'order' => 'nullable|integer|min:0',
            'duration_minutes' => 'nullable|integer|min:0|max:1440',
            'allow_download' => 'nullable|boolean',
            'show_slide_numbers' => 'nullable|boolean',
            'autoplay_video' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'title.min' => 'Le titre doit contenir au moins 3 caractères',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères',
            'content.max' => 'Le contenu ne doit pas dépasser 10000 caractères',
            'video_url.url' => 'L\'URL vidéo est invalide',
            'external_link.url' => 'L\'URL externe est invalide',
            'duration_minutes.max' => 'La durée ne doit pas dépasser 1440 minutes',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('title')) {
            $this->merge(['title' => trim($this->title ?? '')]);
        }

        if ($this->has('description')) {
            $this->merge(['description' => trim($this->description ?? '')]);
        }

        if ($this->has('content')) {
            // Fix #212 : `$this->content` = corps HTTP brut, pas l'input.
            $this->merge(['content' => trim((string) $this->input('content', ''))]);
        }
    }
}
