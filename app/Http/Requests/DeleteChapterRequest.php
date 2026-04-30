<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates chapter delete request (DELETE /api/chapters/{id}).
 *
 * ## Purpose
 * Authorize deletion of chapter.
 * Chapter belongs to lesson → authorize via lesson ownership.
 * No input fields required.
 *
 * ## Authorization Model
 * Only lesson owner or admin can delete:
 * 1. User authenticated
 * 2. User is teacher/coordinator/admin
 * 3. Chapter's lesson owner == user OR is admin
 * 4. Chapter belongs to user's institution (multi-tenant)
 */
final class DeleteChapterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (!$user->isTeacher() && !$user->isCoordinator() && !$user->isAdmin()) {
            return false;
        }

        $chapter = \App\Models\Chapter::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (!$chapter) {
            return false;
        }

        $lesson = \App\Models\Lesson::find($chapter->lesson_id);
        if (!$lesson || $lesson->institution_id !== $user->institution_id) {
            return false;
        }

        if (!$user->isAdmin() && $lesson->enseignant_id !== $user->id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
