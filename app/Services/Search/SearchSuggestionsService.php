<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Suggestions de recherche (autocomplete) — split-19/search.
 *
 * Extrait de `SearchController::suggestions` : collecte des chaînes
 * candidates depuis 3 sources (noms d'utilisateurs si admin/coordinateur,
 * titres de leçons, titres d'évaluations), dédoublonne, tronque à
 * `$limit`.
 *
 * ## DI strict (§1.6 D)
 *
 * Aucune dépendance externe (pas de Facade, pas de `app()`). Service
 * pur Eloquent.
 *
 * @see app/Http/Controllers/API/SearchController.php
 */
final class SearchSuggestionsService
{
    /**
     * Nombre maximal de suggestions retournées.
     */
    private const DEFAULT_LIMIT = 10;

    /**
     * Retourner la liste des suggestions pour `$query` et `$user`.
     *
     * @return array<int, string>
     */
    public function getSuggestions(string $query, User $user): array
    {
        $limit = self::DEFAULT_LIMIT;
        $suggestions = [];

        if ($user->isCoordinator() || $user->isAdmin()) {
            /** @var array<int, string> $userSuggestions */
            $userSuggestions = User::where('name', 'LIKE', "%{$query}%")
                ->limit($limit)
                ->pluck('name')
                ->all();
            $suggestions = array_merge($suggestions, $userSuggestions);
        }

        /** @var array<int, string> $lessonSuggestions */
        $lessonSuggestions = Lesson::where('title', 'LIKE', "%{$query}%")
            ->where(function (Builder $q) use ($user): void {
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
            })
            ->limit($limit)
            ->pluck('title')
            ->all();
        $suggestions = array_merge($suggestions, $lessonSuggestions);

        /** @var array<int, string> $evaluationSuggestions */
        $evaluationSuggestions = Evaluation::where('title', 'LIKE', "%{$query}%")
            ->where(function (Builder $q) use ($user): void {
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
            })
            ->limit($limit)
            ->pluck('title')
            ->all();
        $suggestions = array_merge($suggestions, $evaluationSuggestions);

        $suggestions = array_unique($suggestions);
        $suggestions = array_slice($suggestions, 0, $limit);

        return array_values($suggestions);
    }
}
