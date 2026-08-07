<?php

declare(strict_types=1);

namespace App\Services\AdminAnalytics;

use App\Enums\LessonStatus;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Lesson;
use App\Models\User;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Tâches administratives en attente + utilisateurs récents.
 *
 * Extrait de `AdminAnalyticsController::getPendingTasks` et `getRecentUsers`
 * (split `split-23/admin-analytics`, §5 controllers ≤200 l + §1.1
 * services ≤300 l + §1.6 D strict DI).
 *
 * ## Responsabilité (SRP)
 *
 * Deux lectures **courtes** et **non agrégées dans le temps** destinées au
 * widget « À faire » du dashboard admin :
 *
 *   - {@see getPendingTasks()} — compteurs d'éléments en attente d'action
 *     (notes manquantes, users inactifs, drafts).
 *   - {@see getRecentUsers()} — N derniers inscrits, projection minimale.
 *
 * ## SECURITY — Isolation cross-tenant (issue #23)
 *
 * Toutes les requêtes Eloquent reposent sur le trait
 * {@see \App\Models\Traits\BelongsToInstitution} qui filtre **automatiquement**
 * par `institution_id` via un global scope. La clé de cache de `getPendingTasks`
 * est en plus scopée par le **slug** retourné par
 * {@see TenantManager::getResolvedSlug()} (fail-secure) pour empêcher toute
 * fuite cross-tenant via `Cache::remember`.
 *
 * `getRecentUsers()` n'est pas caché : la projection est petite, la requête
 * est triviale et la fraîcheur compte plus que l'économie d'un round-trip
 * DB pour un widget « derniers inscrits ».
 *
 * ## DI strict (§1.6 D)
 *
 *   - `CacheRepository` (jamais `Cache::` Facade).
 *   - `TenantManager` (jamais `app(TenantManager::class)`).
 *   - PSR-3 `LoggerInterface` (jamais `Log::` Facade).
 *
 * @see app/Http/Controllers/API/AdminAnalyticsController.php
 * @see app/Services/TenantManager.php
 */
final class PendingTasksService
{
    /**
     * TTL court (1 min) — un admin qui ouvre le widget « À faire » doit voir
     * presque immédiatement l'effet d'une notation ou d'une publication.
     */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Seuil au-delà duquel une soumission non notée est marquée « urgent ».
     */
    private const URGENT_GRADING_THRESHOLD_DAYS = 3;

    /**
     * Seuil au-delà duquel un user est considéré « inactif » (basé sur
     * `updated_at` du modèle User — touché par les actions persistées).
     */
    private const INACTIVE_USER_THRESHOLD_DAYS = 30;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Récupérer les compteurs des tâches en attente pour le widget admin.
     *
     * @return array{
     *     pending_grading: array{count: int, urgent_count: int},
     *     inactive_users: array{count: int},
     *     unpublished_evaluations: array{count: int},
     *     draft_lessons: array{count: int}
     * }
     */
    public function getPendingTasks(): array
    {
        $institution = $this->tenantManager->getResolvedSlug();
        $cacheKey = 'admin_pending_tasks_' . $institution;

        $this->logger->debug('admin_analytics.pending_tasks.request', [
            'institution' => $institution,
        ]);

        /** @var array{
         *     pending_grading: array{count: int, urgent_count: int},
         *     inactive_users: array{count: int},
         *     unpublished_evaluations: array{count: int},
         *     draft_lessons: array{count: int}
         * } */
        return $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->aggregatePendingTasks(),
        );
    }

    /**
     * Récupérer les `$limit` derniers utilisateurs inscrits — projection
     * minimale pour le widget dashboard. Non caché (cf. PHPDoc de la classe).
     *
     * @param  int<1, 100>  $limit
     * @return Collection<int, User>
     */
    public function getRecentUsers(int $limit = 10): Collection
    {
        $this->logger->debug('admin_analytics.recent_users.request', [
            'limit' => $limit,
        ]);

        // BelongsToInstitution global scope filtre par institution_id.
        /** @var Collection<int, User> */
        return User::orderBy('created_at', 'desc')
            ->take($limit)
            ->select(['id', 'name', 'email', 'role', 'created_at'])
            ->get();
    }

    /**
     * Agrégat effectif des compteurs « À faire ».
     *
     * @return array{
     *     pending_grading: array{count: int, urgent_count: int},
     *     inactive_users: array{count: int},
     *     unpublished_evaluations: array{count: int},
     *     draft_lessons: array{count: int}
     * }
     */
    private function aggregatePendingTasks(): array
    {
        $urgentCutoff = Carbon::now()->subDays(self::URGENT_GRADING_THRESHOLD_DAYS);
        $inactiveCutoff = Carbon::now()->subDays(self::INACTIVE_USER_THRESHOLD_DAYS);

        return [
            // Evaluations non notées — BelongsToInstitution global scope filtre par institution_id
            'pending_grading' => [
                'count' => EvaluationSubmission::whereNull('note_sur_20')->count(),
                'urgent_count' => EvaluationSubmission::whereNull('note_sur_20')
                    ->where('submitted_at', '<', $urgentCutoff)
                    ->count(),
            ],

            // Utilisateurs inactifs (30 jours)
            'inactive_users' => [
                'count' => User::where('updated_at', '<', $inactiveCutoff)->count(),
            ],

            // Evaluations non publiées
            'unpublished_evaluations' => [
                'count' => Evaluation::where('status', '!=', 'published')->count(),
            ],

            // Leçons en brouillon
            'draft_lessons' => [
                'count' => Lesson::where('status', LessonStatus::Draft->value)->count(),
            ],
        ];
    }
}
