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
 * Aucune Facade ni `app()` : la seule collaboration ({@see TeacherOwnershipScope})
 * est injectée par le constructeur.
 *
 * ## Correctifs #575
 *
 * Le filtre enseignant portait sur `teacher_id` et la recherche d'évaluations
 * sur `title` — deux colonnes inexistantes (les vraies : `enseignant_id`,
 * `klassci_enseignant_id`, `titre`). Invisible sous SQLite, erreur 1054 sous
 * MySQL.
 *
 * @see app/Http/Controllers/API/SearchController.php
 * @see .claude/specs/575-search-teacher-id/design.md
 */
final class SearchSuggestionsService
{
    /**
     * Nombre maximal de suggestions retournées.
     */
    private const DEFAULT_LIMIT = 10;

    public function __construct(
        private readonly TeacherOwnershipScope $ownership,
    ) {
    }

    /**
     * Retourner la liste des suggestions pour `$query` et `$user`.
     *
     * @return array<int, string>
     */
    public function getSuggestions(string $query, User $user): array
    {
        $limit = self::DEFAULT_LIMIT;
        $suggestions = [];

        if ($user->isManager()) {
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
                    $this->ownership->applyToLessons($q, $user);
                }
            })
            ->limit($limit)
            ->pluck('title')
            ->all();
        $suggestions = array_merge($suggestions, $lessonSuggestions);

        /** @var array<int, string> $evaluationSuggestions */
        $evaluationSuggestions = Evaluation::where('titre', 'LIKE', "%{$query}%")
            ->where(function (Builder $q) use ($user): void {
                if ($user->isTeacher()) {
                    $this->ownership->applyToEvaluations($q, $user);
                }
            })
            ->limit($limit)
            ->pluck('titre')
            ->all();
        $suggestions = array_merge($suggestions, $evaluationSuggestions);

        $suggestions = array_unique($suggestions);
        $suggestions = array_slice($suggestions, 0, $limit);

        return array_values($suggestions);
    }
}
