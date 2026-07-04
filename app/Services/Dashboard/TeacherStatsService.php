<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Compteurs de contenu d'un enseignant : matières, classes, évaluations,
 * leçons (issue #364).
 *
 * Extrait verbatim de `TeacherStatsController::getStats`. Particularité
 * héritée : l'agrégation est keyée sur `$user->klassci_id` (identifiant
 * KLASSCI), PAS sur `$user->id` — contrairement au dashboard enseignant.
 * Préservé tel quel.
 *
 * Les 4 blocs pluck/foreach dupliqués du controller (matières × classes ×
 * leçons × évaluations) sont factorisés en {@see distinctUnionCount()} —
 * même SQL émis, même sémantique d'union (les sets PHP par clé de tableau
 * deviennent `merge()->unique()`, déduplication non stricte identique à
 * la coercition des clés de tableau).
 *
 * ## Dette héritée TRACÉE (préexistante, hors périmètre #364)
 *
 * La table `evaluations` n'a ni `enseignant_id`, ni `matiere_id`, ni
 * `classe_id` (uniquement `klassci_*`). Sous SQLite la sémantique
 * "double-quoted string literal" neutralise le `where` → les évaluations
 * ne contribuent à aucun compteur. Verrouillé par TeacherStatsServiceTest.
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
        $klassciId = $user->klassci_id;

        return [
            'matieres' => $this->distinctUnionCount('matiere_id', $klassciId),
            'classes' => $this->distinctUnionCount('classe_id', $klassciId),
            // @phpstan-ignore argument.type (colonne fantôme héritée — evaluations.enseignant_id absent du schéma, préservé verbatim, cf. PR #364)
            'evaluations' => Evaluation::where('enseignant_id', $klassciId)->count(),
            'lessons' => Lesson::where('enseignant_id', $klassciId)->count(),
        ];
    }

    /**
     * Nombre de valeurs distinctes de `$column` sur l'union
     * leçons ∪ évaluations de l'enseignant (nulls exclus).
     */
    private function distinctUnionCount(string $column, mixed $klassciId): int
    {
        return $this->distinctColumnValues(Lesson::query(), $column, $klassciId)
            ->merge($this->distinctColumnValues(Evaluation::query(), $column, $klassciId))
            ->unique()
            ->count();
    }

    /**
     * Valeurs distinctes non nulles de `$column` pour l'enseignant donné.
     *
     * @param \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model> $query
     * @return Collection<int, mixed>
     */
    private function distinctColumnValues($query, string $column, mixed $klassciId): Collection
    {
        return $query
            ->where('enseignant_id', $klassciId)
            ->whereNotNull($column)
            ->distinct()
            ->pluck($column);
    }
}
