<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReservesLessonContentToTeacher;
use App\Services\Search\TeacherOwnershipScope;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates chapter reorder request (POST /api/lessons/{lessonId}/chapters/reorder).
 *
 * ## Purpose
 * Reorder chapters within a lesson (drag & drop).
 * Input: array of chapters with id + new order.
 *
 * ## Authorization Model
 * Only the teacher who owns the lesson can reorder (#1167, the management only reads):
 * 1. User authenticated
 * 2. User is a teacher
 * 3. Lesson owner == user
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
    use ReservesLessonContentToTeacher;

    public function authorize(TeacherOwnershipScope $auteurs): bool
    {
        // L'enseignant auteur de la leçon, et lui seul (#1167) : la gestion consulte.
        return $this->enseignantAuteurDeLaLecon($this->route('lessonId'), $auteurs);
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
