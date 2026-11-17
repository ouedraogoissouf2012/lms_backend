<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReservesLessonContentToTeacher;
use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Search\TeacherOwnershipScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates chapter update request (PATCH /api/chapters/{id}).
 *
 * ## Purpose
 * Update chapter metadata (title, description, content, URLs).
 * Chapter belongs to lesson → authorize via lesson ownership.
 *
 * ## Authorization Model
 * Only the teacher who owns the lesson can update (#1167, the management only reads):
 * 1. User authenticated
 * 2. User is a teacher
 * 3. Chapter's lesson owner == user
 * 4. Chapter belongs to user's institution (multi-tenant)
 *
 * If ANY check fails → 403 Forbidden
 */
final class UpdateChapterRequest extends FormRequest
{
    use ReservesLessonContentToTeacher;

    public function authorize(TeacherOwnershipScope $auteurs): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        // Chapter must exist and belong to user's institution
        $chapter = Chapter::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (! $chapter) {
            return false;
        }

        // L'enseignant auteur de sa leçon, et lui seul (#1167) : la gestion consulte.
        $lesson = Lesson::find($chapter->lesson_id);

        return $lesson instanceof Lesson
            && $lesson->institution_id === $user->institution_id
            && $this->enseignantAuteur($lesson, $user, $auteurs);
    }

    public function rules(): array
    {
        return [
            'title' => 'sometimes|string|min:3|max:255',
            'description' => 'nullable|string|max:1000',
            'content_type' => 'nullable|string|in:text,video,pdf,powerpoint,word,image,link,quiz',
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
