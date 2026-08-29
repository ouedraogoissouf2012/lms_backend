<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Compteurs de contenu d'un enseignant : matières, classes, évaluations,
 * leçons (issue #364).
 *
 * Les leçons restent keyées sur `lessons.enseignant_id` (sémantique
 * existante), et les évaluations utilisent les colonnes réellement migrées
 * `evaluations.klassci_*` (#401).
 *
 * ## SRP / DI (§1.6)
 *
 * Une seule responsabilité : produire les compteurs de contenu d'un
 * enseignant. Aucune dépendance à injecter — le garde de rôle (403) et le
 * catch (500) restent des préoccupations HTTP dans le controller.
 *
 * @see app/Http/Controllers/API/TeacherStatsController.php
 */
final class TeacherStatsService
{
    /**
     * Construire les compteurs de contenu de l'enseignant.
     *
     * @return array{matieres: int, classes: int, evaluations: int, lessons: int}
     */
    public function buildStats(User $user): array
    {
        $klassciTeacherId = $user->klassci_enseignant_id;

        if ($klassciTeacherId === null) {
            return ['matieres' => 0, 'classes' => 0, 'evaluations' => 0, 'lessons' => 0];
        }

        return [
            'matieres' => $this->distinctUnionCount('matiere_id', 'klassci_matiere_id', $klassciTeacherId),
            'classes' => $this->distinctUnionCount('classe_id', 'klassci_classe_id', $klassciTeacherId),
            'evaluations' => Evaluation::where('klassci_enseignant_id', $klassciTeacherId)->count(),
            'lessons' => Lesson::where('enseignant_id', $klassciTeacherId)->count(),
        ];
    }

    /**
     * Nombre de valeurs distinctes de `$column` sur l'union
     * leçons ∪ évaluations de l'enseignant (nulls exclus).
     */
    private function distinctUnionCount(string $lessonColumn, string $evaluationColumn, int $klassciTeacherId): int
    {
        return $this->distinctColumnValues(Lesson::query(), 'enseignant_id', $lessonColumn, $klassciTeacherId)
            ->merge($this->distinctColumnValues(Evaluation::query(), 'klassci_enseignant_id', $evaluationColumn, $klassciTeacherId))
            ->unique()
            ->count();
    }

    /**
     * Valeurs distinctes non nulles de `$column` pour l'enseignant donné.
     *
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Collection<int, mixed>
     */
    private function distinctColumnValues($query, string $ownerColumn, string $valueColumn, int $klassciTeacherId): Collection
    {
        return $query
            ->where($ownerColumn, $klassciTeacherId)
            ->whereNotNull($valueColumn)
            ->distinct()
            ->pluck($valueColumn);
    }
}
