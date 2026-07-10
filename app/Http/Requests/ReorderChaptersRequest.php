<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates chapter reorder request (POST /api/lessons/{lessonId}/chapters/reorder).
 *
 * ## Purpose
 * Reorder chapters within a lesson (drag & drop).
 * Input: array of chapters with id + new order.
 *
 * ## Authorization Model
 * Only lesson owner or admin can reorder:
 * 1. User authenticated
 * 2. User is teacher/coordinator/admin
 * 3. Lesson owner == user OR is admin
 * 4. Lesson belongs to user's institution (multi-tenant)
 *
 * ## Input Structure
 * POST /api/lessons/{lessonId}/chapters/reorder
 * {
 *   "chapters": [
 *     {"id": 1, "order": 0},
 *     {"id": 2, "order": 1},
 *     {"id": 3, "order": 2}
 *   ]
 * }
 */
final class ReorderChaptersRequest extends FormRequest
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

        $lesson = \App\Models\Lesson::where('id', $this->route('lessonId'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (!$lesson) {
            return false;
        }

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
        return [
            'chapters' => 'required|array|min:1',
            'chapters.*.id' => 'required|integer|exists:chapters,id',
            'chapters.*.order' => 'required|integer|min:0',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'chapters.required' => 'Un tableau de chapitres est requis',
            'chapters.array' => 'Les chapitres doivent être un tableau',
            'chapters.min' => 'Au moins un chapitre doit être fourni',
            'chapters.*.id.required' => 'L\'ID du chapitre est requis',
            'chapters.*.id.exists' => 'Le chapitre n\'existe pas',
            'chapters.*.order.required' => 'L\'ordre du chapitre est requis',
            'chapters.*.order.integer' => 'L\'ordre doit être un nombre entier',
            'chapters.*.order.min' => 'L\'ordre ne peut pas être négatif',
        ];
    }
}
