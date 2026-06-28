<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Recherche globale multi-ressources (split-19/search).
 *
 * Extrait de `SearchController::globalSearch` : centralise la recherche
 * cross-resources (utilisateurs, leçons, évaluations, classes KLASSCI,
 * matières KLASSCI) — agrégation, scoping par rôle, cache 5 min.
 *
 * ## DI strict (§1.6 D)
 *
 * Aucune Facade (`Cache`, `Log`) ni `app()` : `CacheRepository`, PSR-3
 * `LoggerInterface` et `KlassciProxyService` sont injectés.
 *
 * ## Comportement
 *
 * Aucun changement runtime : la logique de filtrage par rôle et les
 * clauses SQL (`LIKE`, colonnes `teacher_id`, `content`, ...) sont
 * conservées verbatim depuis le god-controller, y compris les éventuels
 * bugs historiques. Toute correction sera traitée dans une PR dédiée.
 *
 * @see app/Http/Controllers/API/SearchController.php
 */
final class GlobalSearchService
{
    /**
     * TTL du cache des résultats agrégés (5 minutes).
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly KlassciProxyService $klassci,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Lancer une recherche globale agrégée.
     *
     * @return array{
     *     results: array{
     *         users: array<int, array<string, mixed>>,
     *         lessons: array<int, array<string, mixed>>,
     *         evaluations: array<int, array<string, mixed>>,
     *         classes: array<int, array<string, mixed>>,
     *         matieres: array<int, array<string, mixed>>
     *     },
     *     total: int,
     *     categories: array<string, int>
     * }
     */
    public function search(string $query, User $user, int $limit = 5): array
    {
        $cacheKey = 'global_search_' . md5($query . $user->id . $limit);

        $results = $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->aggregate($query, $user, $limit),
        );

        $total = 0;
        foreach ($results as $bucket) {
            $total += count($bucket);
        }

        return [
            'results' => $results,
            'total' => $total,
            'categories' => [
                'users' => count($results['users']),
                'lessons' => count($results['lessons']),
                'evaluations' => count($results['evaluations']),
                'classes' => count($results['classes']),
                'matieres' => count($results['matieres']),
            ],
        ];
    }

    /**
     * Agréger les 5 sources sans cache.
     *
     * @return array{
     *     users: array<int, array<string, mixed>>,
     *     lessons: array<int, array<string, mixed>>,
     *     evaluations: array<int, array<string, mixed>>,
     *     classes: array<int, array<string, mixed>>,
     *     matieres: array<int, array<string, mixed>>
     * }
     */
    private function aggregate(string $query, User $user, int $limit): array
    {
        return [
            'users' => $this->searchUsers($query, $user, $limit),
            'lessons' => $this->searchLessons($query, $user, $limit),
            'evaluations' => $this->searchEvaluations($query, $user, $limit),
            'classes' => $this->searchClasses($query, $user, $limit),
            'matieres' => $this->searchMatieres($query, $user, $limit),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchUsers(string $query, User $user, int $limit): array
    {
        if (! $user->isManager()) {
            return [];
        }

        return User::where(function (Builder $q) use ($query): void {
            $q->where('name', 'LIKE', "%{$query}%")
                ->orWhere('email', 'LIKE', "%{$query}%");
        })
            ->limit($limit)
            ->get()
            ->map(fn (User $u): array => [
                'id' => $u->id,
                'title' => $u->name,
                'subtitle' => $u->email,
                'description' => $u->role_display_name,
                'type' => 'user',
                'icon' => 'UserIcon',
                'url' => '/admin/users/' . $u->id,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchLessons(string $query, User $user, int $limit): array
    {
        return Lesson::where(function (Builder $q) use ($query): void {
            $q->where('title', 'LIKE', "%{$query}%")
                ->orWhere('description', 'LIKE', "%{$query}%")
                ->orWhere('content', 'LIKE', "%{$query}%");
        })
            ->where(function (Builder $q) use ($user): void {
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
                if ($user->isStudent()) {
                    $q->where('status', 'published');
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn (Lesson $lesson): array => [
                'id' => $lesson->id,
                'title' => $lesson->title,
                'subtitle' => 'Leçon',
                'description' => Str::limit((string) $lesson->description, 100),
                'type' => 'lesson',
                'icon' => 'BookOpenIcon',
                'url' => '/teacher/lessons/' . $lesson->id,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchEvaluations(string $query, User $user, int $limit): array
    {
        return Evaluation::where(function (Builder $q) use ($query): void {
            $q->where('title', 'LIKE', "%{$query}%")
                ->orWhere('description', 'LIKE', "%{$query}%");
        })
            ->where(function (Builder $q) use ($user): void {
                if ($user->isTeacher()) {
                    $q->where('teacher_id', $user->id);
                }
                if ($user->isStudent()) {
                    $q->where('status', 'published');
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn (Evaluation $evaluation): array => [
                'id' => $evaluation->id,
                'title' => $evaluation->title,
                'subtitle' => 'Évaluation',
                'description' => Str::limit((string) $evaluation->description, 100),
                'type' => 'evaluation',
                'icon' => 'DocumentTextIcon',
                'url' => '/teacher/evaluations/' . $evaluation->id,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchClasses(string $query, User $user, int $limit): array
    {
        if (! $user->isStaff()) {
            return [];
        }

        try {
            $allClasses = $this->klassci->getClasses();
        } catch (Throwable $e) {
            $this->logger->error('Erreur recherche classes KLASSCI:', ['error' => $e->getMessage()]);

            return [];
        }

        return collect($allClasses)
            ->filter(fn (array $classe): bool => stripos((string) ($classe['name'] ?? ''), $query) !== false
                || stripos((string) ($classe['filiere']['name'] ?? ''), $query) !== false
                || stripos((string) ($classe['niveau']['name'] ?? ''), $query) !== false)
            ->take($limit)
            ->map(fn (array $classe): array => [
                'id' => $classe['id'],
                'title' => $classe['name'],
                'subtitle' => 'Classe',
                'description' => ($classe['filiere']['name'] ?? '') . ' - ' . ($classe['niveau']['name'] ?? ''),
                'type' => 'classe',
                'icon' => 'BuildingLibraryIcon',
                'url' => '/admin/classes/' . $classe['id'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function searchMatieres(string $query, User $user, int $limit): array
    {
        if (! $user->isStaff()) {
            return [];
        }

        try {
            $allMatieres = $this->klassci->getMatieres();
        } catch (Throwable $e) {
            $this->logger->error('Erreur recherche matières KLASSCI:', ['error' => $e->getMessage()]);

            return [];
        }

        return collect($allMatieres)
            ->filter(fn (array $matiere): bool => stripos((string) ($matiere['nom'] ?? ''), $query) !== false
                || stripos((string) ($matiere['code'] ?? ''), $query) !== false)
            ->take($limit)
            ->map(fn (array $matiere): array => [
                'id' => $matiere['id'],
                'title' => $matiere['nom'],
                'subtitle' => 'Matière',
                'description' => 'Code: ' . ($matiere['code'] ?? 'N/A'),
                'type' => 'matiere',
                'icon' => 'AcademicCapIcon',
                'url' => '/admin/matieres/' . $matiere['id'],
            ])
            ->values()
            ->all();
    }
}
