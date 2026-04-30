<?php

namespace App\Http\Requests;

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
 * Only lesson owner or admin can publish:
 * 1. User authenticated
 * 2. User is teacher/coordinator/admin
 * 3. User owns the lesson (enseignant_id == user->id) OR is admin
 * 4. Lesson belongs to user's institution (multi-tenant)
 *
 * If ANY check fails → 403 Forbidden
 */
final class PublishLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        // Check 1: User must be authenticated
        if (!$user) {
            return false;
        }

        // Check 2: Only teachers/coordinators/admins can publish
        if (!$user->isTeacher() && !$user->isCoordinator() && !$user->isAdmin()) {
            return false;
        }

        // Check 3: Lesson must exist and belong to user's institution
        $lesson = \App\Models\Lesson::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (!$lesson) {
            return false;
        }

        // Check 4: User must be lesson owner OR admin
        if (!$user->isAdmin() && $lesson->enseignant_id !== $user->id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        // No input validation for publish action
        return [];
    }
}
