<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReservesLessonContentToTeacher;
use App\Services\Search\TeacherOwnershipScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates lesson publish request (POST /api/lessons/{id}/publish).
 *
 * ## Purpose
 * Authorize publication of a lesson (draft → published).
 * No input fields required — only authorization checks.
 *
 * Sets published_at = now() and status = 'published' in controller.
 * Triggers notifications to enrolled students.
 *
 * ## Authorization Model
 * Only the teacher who owns the lesson can publish (#1167, the management only reads):
 * 1. User authenticated
 * 2. User is a teacher
 * 3. User owns the lesson (enseignant_id == user->id)
 * 4. Lesson belongs to user's institution (multi-tenant)
 *
 * If ANY check fails → 403 Forbidden
 */
final class PublishLessonRequest extends FormRequest
{
    use ReservesLessonContentToTeacher;

    public function authorize(TeacherOwnershipScope $auteurs): bool
    {
        // L'enseignant auteur de la leçon, et lui seul (#1167) : la gestion consulte.
        return $this->enseignantAuteurDeLaLecon($this->route('id'), $auteurs);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // No input validation for publish action
        return [];
    }
}
