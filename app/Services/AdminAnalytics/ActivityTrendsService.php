<?php

declare(strict_types=1);

namespace App\Services\AdminAnalytics;

use App\Models\EvaluationSubmission;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Tendances d'activité utilisateurs sur N derniers jours.
 *
 * Extrait de `AdminAnalyticsController::getActivityTrends`
 * (split `split-23/admin-analytics`, §5 controllers ≤200 l + §1.1
 * services ≤300 l + §1.6 D strict DI).
 *
 * ## Responsabilité (SRP)
 *
 * Agréger les **séries temporelles journalières** des trois métriques d'activité
 * critique (inscriptions users, soumissions évaluations, tentatives quiz) sur
 * une fenêtre glissante de `N` jours, puis sérialiser le résultat dans le
 * format `Chart.js` attendu par le dashboard front (labels + datasets typés).
 *
 * ## SECURITY — Isolation cross-tenant (issue #23)
 *
 * Toutes les requêtes Eloquent reposent sur le trait
 * {@see \App\Models\Traits\BelongsToInstitution} qui filtre **automatiquement**
 * par `institution_id` via un global scope. La clé de cache est en plus
 * scopée par le **slug** retourné par {@see TenantManager::getResolvedSlug()}
 * (fail-secure : lève une exception si le tenant n'est pas résolu) pour
 * empêcher toute fuite cross-tenant via `Cache::remember`.
 *
 * NE PAS remplacer `getResolvedSlug()` par `md5(klassci_tenant_url)` ou un
 * autre identifiant : la régression originelle (commit antérieur au fix
 * `9a07d04` du 24 mars 2026) faisait collisionner deux écoles partageant
 * le même serveur KLASSCI. Le test
 * `tests/Feature/AdminAnalyticsCacheIsolationTest.php` garde-fou cette
 * invariante.
 *
 * ## DI strict (§1.6 D)
 *
 *   - `CacheRepository` (jamais `Cache::` Facade).
 *   - `TenantManager` (jamais `app(TenantManager::class)`).
 *   - PSR-3 `LoggerInterface` (jamais `Log::` Facade).
 *
 * @see app/Http/Controllers/API/AdminAnalyticsController.php
 * @see app/Services/TenantManager.php
 * @see tests/Feature/AdminAnalyticsCacheIsolationTest.php
 */
final class ActivityTrendsService
{
    /**
     * TTL du cache (5 min) — compromis entre fraîcheur dashboard et coût
     * d'agrégation de 30 jours de données sur 3 tables.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Récupérer les tendances d'activité journalières sur `$days` jours.
     *
     * @param  int<1, 365>  $days  Nombre de jours dans la fenêtre glissante.
     * @return array{
     *     labels: array<int, string>,
     *     datasets: array<int, array{
     *         label: string,
     *         data: array<int, int>,
     *         borderColor: string,
     *         backgroundColor: string,
     *         tension: float
     *     }>
     * }
     */
    public function getTrends(int $days = 30): array
    {
        $institution = $this->tenantManager->getResolvedSlug();
        $cacheKey = 'admin_analytics_activity_trends_' . $institution;

        $this->logger->debug('admin_analytics.activity_trends.request', [
            'institution' => $institution,
            'days' => $days,
        ]);

        /** @var array{
         *     labels: array<int, string>,
         *     datasets: array<int, array{
         *         label: string,
         *         data: array<int, int>,
         *         borderColor: string,
         *         backgroundColor: string,
         *         tension: float
         *     }>
         * } */
        return $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->aggregate($days),
        );
    }

    /**
     * Agréger les trois séries journalières puis projeter en format Chart.js.
     *
     * @return array{
     *     labels: array<int, string>,
     *     datasets: array<int, array{
     *         label: string,
     *         data: array<int, int>,
     *         borderColor: string,
     *         backgroundColor: string,
     *         tension: float
     *     }>
     * }
     */
    private function aggregate(int $days): array
    {
        $endDate = Carbon::now();
        $startDate = Carbon::now()->subDays($days);

        // Connexions par jour — BelongsToInstitution global scope filtre par institution_id.
        // `pluck('count', 'date')` retourne directement `array<string, int>`, ce qui
        // évite l'accès magique `?->count` sur un modèle Eloquent (mal typé par PHPStan)
        // tout en gardant les requêtes parfaitement identiques côté SQL.
        $dailyRegistrations = $this->pluckDailyCounts(
            User::whereBetween('created_at', [$startDate, $endDate]),
            'created_at',
        );

        // Soumissions d'évaluations par jour
        $dailyEvaluationSubmissions = $this->pluckDailyCounts(
            EvaluationSubmission::whereBetween('submitted_at', [$startDate, $endDate]),
            'submitted_at',
        );

        // Tentatives de quiz par jour
        $dailyQuizAttempts = $this->pluckDailyCounts(
            QuizAttempt::whereBetween('started_at', [$startDate, $endDate]),
            'started_at',
        );

        $labels = [];
        $registrations = [];
        $evaluations = [];
        $quizzes = [];

        for ($date = clone $startDate; $date->lte($endDate); $date->addDay()) {
            $dateString = $date->format('Y-m-d');
            $labels[] = $date->format('d M');

            $registrations[] = $dailyRegistrations[$dateString] ?? 0;
            $evaluations[] = $dailyEvaluationSubmissions[$dateString] ?? 0;
            $quizzes[] = $dailyQuizAttempts[$dateString] ?? 0;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Inscriptions',
                    'data' => $registrations,
                    'borderColor' => '#3b82f6',
                    'backgroundColor' => 'rgba(59, 130, 246, 0.1)',
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Soumissions Evaluations',
                    'data' => $evaluations,
                    'borderColor' => '#f59e0b',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.1)',
                    'tension' => 0.4,
                ],
                [
                    'label' => 'Tentatives Quiz',
                    'data' => $quizzes,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16, 185, 129, 0.1)',
                    'tension' => 0.4,
                ],
            ],
        ];
    }

    /**
     * `GROUP BY DATE(<column>)` puis `pluck('count', 'date')` — retourne une
     * `array<date_string, int>` immédiatement indexée, ce qui supprime l'accès
     * magique `?->count` ambigu pour PHPStan et garantit un cast `(int)`
     * propre du côté de la lecture.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $builder  Builder déjà filtré par fenêtre temporelle.
     * @param  string  $dateColumn  Colonne timestamp à agréger journalièrement.
     * @return array<string, int>  Clé = `'YYYY-MM-DD'`, valeur = nombre d'événements.
     */
    private function pluckDailyCounts(Builder $builder, string $dateColumn): array
    {
        // On collecte d'abord les rows brutes (stdClass-like) puis on construit
        // manuellement le tableau `date => count` : `pluck('count', 'date')`
        // sur un Eloquent Builder rejette l'alias `count` (PHPStan attend une
        // colonne du modèle), donc on passe par `get()->reduce()` qui retourne
        // un tableau typé `array<string, int>`.
        $rows = $builder
            ->selectRaw('DATE(' . $dateColumn . ') as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            /** @var string $date */
            $date = $row->getAttribute('date');
            /** @var int|string $count */
            $count = $row->getAttribute('count');
            $out[$date] = (int) $count;
        }

        return $out;
    }
}
