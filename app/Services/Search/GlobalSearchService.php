<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Enums\LessonStatus;
use App\Models\Evaluation;
use App\Models\Lesson;
use App\Models\User;
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
 * `LoggerInterface` et {@see KlassciSearchSources} sont injectés.
 *
 * ## Comportement
 *
 * Le split-19 avait conservé les clauses SQL verbatim depuis le god-controller,
 * bugs historiques compris. #575 en a corrigé deux, tous deux invisibles sous
 * SQLite et fatals (erreur 1054) sous MySQL :
 *
 *   - le filtre enseignant portait sur `teacher_id`, colonne inexistante —
 *     l'appartenance est désormais déléguée à {@see TeacherOwnershipScope} ;
 *   - la recherche d'évaluations portait sur `title`, alors que la colonne
 *     migrée s'appelle `titre` (la clé `title` de la réponse, elle, ne change
 *     pas : c'est le contrat client).
 *
 * ## Dégradation par source (#505)
 *
 * Les 3 sources locales ne peuvent pas « tomber » indépendamment : si la base est
 * indisponible, il n'y a plus de requête du tout. Les 2 sources KLASSCI, elles,
 * dépendent d'un service tiers — et leur panne était jusqu'ici indiscernable d'un
 * « aucun résultat », puis figée 5 minutes dans le cache. Désormais :
 *
 *   - la source en panne est NOMMÉE dans `sources_failed` ;
 *   - un agrégat incomplet est mémorisé AVEC son drapeau et pour 30 s seulement,
 *     jamais 5 minutes : il n'est donc jamais servi comme s'il était complet, et
 *     la recherche redevient entière peu après le rétablissement de KLASSCI.
 *
 * Le TTL court plutôt qu'aucun cache : `TenantScopedCache::remember()` ne mémorise
 * PAS une exception, donc le cache KLASSCI de 600 s ne protège rien tant que le
 * service est en panne — seul `KlassciCircuitBreaker` borne le trafic sortant, et
 * rien ne bornerait les trois `LIKE '%…%'` locaux rejoués à chaque frappe.
 *
 * @see app/Http/Controllers/API/SearchController.php
 * @see .claude/specs/575-search-teacher-id/design.md
 * @see .claude/specs/505-search-degradation/design.md
 *
 * @phpstan-import-type SearchResults from SearchAggregate
 */
final class GlobalSearchService
{
    public function __construct(
        private readonly SearchResultCache $cache,
        private readonly LoggerInterface $logger,
        private readonly TeacherOwnershipScope $ownership,
        private readonly KlassciSearchSources $klassciSources,
    ) {
    }

    /**
     * Lancer une recherche globale agrégée.
     *
     * @return array{
     *     results: SearchResults,
     *     total: int,
     *     categories: array<string, int>,
     *     sources_failed: list<string>
     * }
     */
    public function search(string $query, User $user, int $limit = 5): array
    {
        $aggregate = $this->cachedOrFresh($query, $user, $limit);
        $results = $aggregate->results;

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
            'sources_failed' => $aggregate->failedSources,
        ];
    }

    /**
     * Sert l'agrégat depuis le cache, sinon recalcule et mémorise.
     *
     * `remember()` ne conviendrait pas : la DURÉE de mémorisation dépend de l'état
     * de santé des sources, qu'on ne connaît qu'après le calcul.
     */
    private function cachedOrFresh(string $query, User $user, int $limit): SearchAggregate
    {
        $cached = $this->cache->get($query, $user->id, $limit);

        if ($cached !== null) {
            return $cached;
        }

        $aggregate = $this->aggregate($query, $user, $limit);

        $this->cache->put($query, $user->id, $limit, $aggregate);

        return $aggregate;
    }

    /**
     * Agréger les 5 sources sans cache.
     */
    private function aggregate(string $query, User $user, int $limit): SearchAggregate
    {
        /** @var list<string> $failedSources */
        $failedSources = [];

        // Le tableau est construit AVANT d'instancier l'agrégat : `$failedSources`
        // est alimenté par référence pendant l'évaluation des sources, et le lire
        // au même endroit ferait dépendre la justesse du drapeau de l'ordre
        // d'évaluation des arguments — invisible et cassable au moindre refactor.
        $results = [
            'users' => $this->searchUsers($query, $user, $limit),
            'lessons' => $this->searchLessons($query, $user, $limit),
            'evaluations' => $this->searchEvaluations($query, $user, $limit),
            'classes' => $this->runDegradableSource(
                'classes',
                fn (): array => $this->klassciSources->searchClasses($query, $user, $limit),
                $failedSources,
            ),
            'matieres' => $this->runDegradableSource(
                'matieres',
                fn (): array => $this->klassciSources->searchMatieres($query, $user, $limit),
                $failedSources,
            ),
        ];

        return new SearchAggregate($results, $failedSources);
    }

    /**
     * Exécute une source susceptible d'être indisponible.
     *
     * Le `catch` couvre la PRODUCTION ENTIÈRE de la source, pas seulement l'appel
     * réseau : un payload KLASSCI mal formé dégrade donc cette seule source au
     * lieu de faire échouer toute la recherche. Le détail technique reste
     * journalisé côté serveur — le client ne reçoit que le nom de la source (§1.2).
     *
     * @param  callable(): array<int, array<string, mixed>>  $source
     * @param  list<string>  $failedSources  Accumulateur des sources en échec.
     * @return array<int, array<string, mixed>>
     */
    private function runDegradableSource(string $name, callable $source, array &$failedSources): array
    {
        try {
            return $source();
        } catch (Throwable $e) {
            $failedSources[] = $name;

            // La classe ET l'exception sont journalisées : le `catch` est large
            // par nécessité (il couvre toute la production de la source), donc il
            // attrape aussi bien une panne KLASSCI qu'un défaut de code ou une
            // institution mal configurée. Sans le type ni la trace, ces cas
            // seraient indiscernables dans les logs — le client, lui, ne reçoit
            // toujours que le nom de la source (§1.2).
            $this->logger->error('Source de recherche indisponible', [
                'source' => $name,
                'exception_class' => $e::class,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            return [];
        }
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
                    $this->ownership->applyToLessons($q, $user);
                }
                if ($user->isStudent()) {
                    $q->where('status', LessonStatus::Published->value);
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
            $q->where('titre', 'LIKE', "%{$query}%")
                ->orWhere('description', 'LIKE', "%{$query}%");
        })
            ->where(function (Builder $q) use ($user): void {
                if ($user->isTeacher()) {
                    $this->ownership->applyToEvaluations($q, $user);
                }
                if ($user->isStudent()) {
                    $q->where('status', 'published');
                }
            })
            ->limit($limit)
            ->get()
            ->map(fn (Evaluation $evaluation): array => [
                'id' => $evaluation->id,
                // Clé `title` conservée (contrat client), valeur lue sur la
                // colonne réellement migrée `titre` — elle valait `null`.
                'title' => $evaluation->titre,
                'subtitle' => 'Évaluation',
                'description' => Str::limit((string) $evaluation->description, 100),
                'type' => 'evaluation',
                'icon' => 'DocumentTextIcon',
                'url' => '/teacher/evaluations/' . $evaluation->id,
            ])
            ->all();
    }
}
