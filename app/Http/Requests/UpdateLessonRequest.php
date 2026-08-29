<?php

namespace App\Http\Requests;

use App\Enums\LessonStatus;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates lesson update request (PATCH /api/lessons/{id}).
 *
 * ## Purpose
 * Update existing lesson with optional fields:
 * - Title, description, type
 * - Links to matiere/classe
 * - Content metadata (duration, difficulty level, objectives)
 * - Status transitions (draft → published)
 *
 * ## Authorization Model
 * Only lesson owner (teacher) or admin can update:
 * 1. User authenticated (auth:sanctum middleware)
 * 2. User is teacher/coordinator/admin
 * 3. User owns the lesson (enseignant_id == user->id) OR is admin
 * 4. Lesson belongs to user's institution (multi-tenant)
 *
 * If ANY check fails → 403 Forbidden
 *
 * ## 10-year perspective
 * All fields optional (sometimes) to allow partial updates.
 * Status changes automatically set/clear published_at in controller.
 * Multi-tenant check prevents data leaks across institutions.
 */
final class UpdateLessonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        // Check 1: User must be authenticated
        if (!$user) {
            return false;
        }

        // Check 2: Only teachers/coordinators/admins can update
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
        return [
            'title' => 'sometimes|string|min:3|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'sometimes|in:cours,tp,td,projet,autre',
            'matiere_id' => 'nullable|integer|exists:matieres,id',
            'classe_id' => 'nullable|integer|exists:classes,id',
            'niveau_difficulte' => 'nullable|in:debutant,intermediaire,avance',
            'objectifs_pedagogiques' => 'nullable|string|max:1000',
            'duree_estimee_minutes' => 'nullable|integer|min:1|max:1440',
            'status' => 'sometimes|in:'.implode(',', LessonStatus::values()),
            'order' => 'sometimes|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Le titre du cours est requis',
            'title.min' => 'Le titre doit contenir au moins 3 caractères',
            'title.max' => 'Le titre ne doit pas dépasser 255 caractères',
            'description.max' => 'La description ne doit pas dépasser 1000 caractères',
            'type.in' => 'Le type de cours est invalide',
            'matiere_id.exists' => 'La matière n\'existe pas',
            'classe_id.exists' => 'La classe n\'existe pas',
            'niveau_difficulte.in' => 'Le niveau de difficulté est invalide',
            'objectifs_pedagogiques.max' => 'Les objectifs ne doivent pas dépasser 1000 caractères',
            'duree_estimee_minutes.max' => 'La durée ne doit pas dépasser 1440 minutes (24h)',
            'status.in' => 'Le statut est invalide',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Trim whitespace
        if ($this->has('title')) {
            $this->merge(['title' => trim($this->title ?? '')]);
        }

        if ($this->has('description')) {
            $this->merge(['description' => trim($this->description ?? '')]);
        }

        if ($this->has('objectifs_pedagogiques')) {
            $this->merge(['objectifs_pedagogiques' => trim($this->objectifs_pedagogiques ?? '')]);
        }
    }
}
