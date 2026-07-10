<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates lesson unpublish request (POST /api/lessons/{id}/unpublish).
 *
 * ## Purpose
 * Authorize unpublication of a lesson (published → draft).
 * No input fields required — only authorization checks.
 *
 * Sets published_at = null and status = 'draft' in controller.
 *
 * ## Authorization Model
 * Only lesson owner or admin can unpublish:
 * 1. User authenticated
 * 2. User is teacher/coordinator/admin
 * 3. User owns the lesson (enseignant_id == user->id) OR is admin
 * 4. Lesson belongs to user's institution (multi-tenant)
 *
 * If ANY check fails → 403 Forbidden
 */
final class UnpublishLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        // Check 1: User must be authenticated
        if (!$user) {
            return false;
        }

        // Check 2: Only teachers/coordinators/admins can unpublish
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // No input validation for unpublish action
        return [];
    }
}
