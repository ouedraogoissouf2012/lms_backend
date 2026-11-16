<?php

namespace App\Http\Requests;

use App\Enums\LessonStatus;
use App\Http\Requests\Concerns\ExigeUneClasseDeLaMatiere;
use App\Models\Classe;
use App\Models\Lesson;
use App\Models\Matiere;
use App\Models\User;
use App\Services\Matiere\ClassesDeLaMatiere;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
    use ExigeUneClasseDeLaMatiere;

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
        $user = auth()->user();

        return [
            'title' => 'sometimes|string|min:3|max:255',
            'description' => 'nullable|string|max:1000',
            'type' => 'sometimes|in:cours,tp,td,projet,autre',
            // Même règle qu'à la création (StoreLessonRequest).
            'matiere_id' => ['nullable', 'integer', Rule::exists('matieres', 'id')->where('institution_id', $user?->institution_id)],
            // La classe suit la règle de la création ({@see after()}).
            'classe_id' => 'nullable|integer',
            'niveau_difficulte' => 'nullable|in:debutant,intermediaire,avance',
            'objectifs_pedagogiques' => 'nullable|string|max:1000',
            'duree_estimee_minutes' => 'nullable|integer|min:1|max:1440',
            'status' => 'sometimes|in:'.implode(',', LessonStatus::values()),
            'order' => 'sometimes|integer|min:0',
        ];
    }

    /**
     * L'édition suit la règle de la création (#1103) : quand la classe ou la
     * matière change, la classe qui en résulte est une classe de la matière qui
     * en résulte. Retirer la classe (`null`) reste permis, comme avant.
     *
     * @return list<callable(Validator): void>
     */
    public function after(ClassesDeLaMatiere $classes): array
    {
        return [function (Validator $validator) use ($classes): void {
            $user = auth()->user();
            if ($validator->errors()->isNotEmpty() || ! $user instanceof User) {
                return;
            }
            if (! $this->has('classe_id') && ! $this->has('matiere_id')) {
                return;
            }

            $lesson = Lesson::query()->where('institution_id', $user->institution_id)->whereKey($this->route('id'))->first();
            $classeId = $this->has('classe_id') ? $this->input('classe_id') : $lesson?->classe_id;
            if ($lesson === null || $classeId === null) {
                return;
            }

            $classe = Classe::query()->where('institution_id', $user->institution_id)->whereKey($classeId)->value('id');
            $matiereId = $this->has('matiere_id') ? $this->input('matiere_id') : $lesson->matiere_id;
            $matiere = $matiereId === null ? null : Matiere::query()->where('institution_id', $user->institution_id)->whereKey($matiereId)->first();

            if ($matiere instanceof Matiere) {
                $this->exigerUneClasseDeLaMatiere($validator, $classes, is_numeric($classe) ? (int) $classe : null, $matiere, $user);
            } elseif (! is_numeric($classe)) {
                $this->refuserLaClasse($validator);
            }
        }];
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
