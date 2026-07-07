<?php

declare(strict_types=1);

namespace App\Services\AdminAnalytics;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Lesson;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\TenantManager;
use Carbon\Carbon;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;

/**
 * Métriques système globales pour le dashboard admin.
 *
 * Extrait de `AdminAnalyticsController::getSystemMetrics`
 * (split `split-23/admin-analytics`, §5 controllers ≤200 l + §1.1
 * services ≤300 l + §1.6 D strict DI).
 *
 * ## Responsabilité (SRP)
 *
 * Calculer les compteurs agrégés (utilisateurs, leçons, évaluations, quiz,
 * performance globale) pour la **vue système** du dashboard administrateur.
 * Sortie destinée aux cartes KPI et tableaux récapitulatifs front.
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
 * autre identifiant : la régression originelle faisait collisionner deux
 * écoles partageant le même serveur KLASSCI. Le test
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
final class SystemMetricsService
{
    /**
     * TTL du cache (5 min) — agrégats coûteux sur plusieurs tables ;
     * dashboard, pas temps-réel.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
        private readonly QueueBackpressureMetricsService $queueMetrics,
        private readonly KlassciBackpressureMetricsService $klassciMetrics,
    ) {
    }

    /**
     * Récupérer les métriques système globales (utilisateurs, leçons,
     * évaluations, quiz, performance).
     *
     * @return array{
     *     users: array{total: int, new_this_week: int, new_this_month: int, by_role: \Illuminate\Support\Collection<string, int>},
     *     lessons: array{total: int, published: int, draft: int, created_this_week: int},
     *     evaluations: array{total: int, published: int, submissions_total: int, submissions_this_week: int, pending_grading: int},
     *     quizzes: array{total_attempts: int, attempts_this_week: int, completed: int, average_score: float|null},
     *     performance: array{average_evaluation_score: float|null, average_quiz_score: float|null, completion_rate: float},
     *     backpressure: array<string, mixed>
     * }
     */
    public function getMetrics(): array
    {
        $institution = $this->tenantManager->getResolvedSlug();
        $cacheKey = 'admin_analytics_system_metrics_' . $institution;

        $this->logger->debug('admin_analytics.system_metrics.request', [
            'institution' => $institution,
        ]);

        /** @var array{
         *     users: array{total: int, new_this_week: int, new_this_month: int, by_role: \Illuminate\Support\Collection<string, int>},
         *     lessons: array{total: int, published: int, draft: int, created_this_week: int},
         *     evaluations: array{total: int, published: int, submissions_total: int, submissions_this_week: int, pending_grading: int},
         *     quizzes: array{total_attempts: int, attempts_this_week: int, completed: int, average_score: float|null},
         *     performance: array{average_evaluation_score: float|null, average_quiz_score: float|null, completion_rate: float},
         *     backpressure: array<string, mixed>
         * } */
        return $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->aggregate(),
        );
    }

    /**
     * Calcul effectif des cinq sections du dashboard système.
     *
     * @return array{
     *     users: array{total: int, new_this_week: int, new_this_month: int, by_role: \Illuminate\Support\Collection<string, int>},
     *     lessons: array{total: int, published: int, draft: int, created_this_week: int},
     *     evaluations: array{total: int, published: int, submissions_total: int, submissions_this_week: int, pending_grading: int},
     *     quizzes: array{total_attempts: int, attempts_this_week: int, completed: int, average_score: float|null},
     *     performance: array{average_evaluation_score: float|null, average_quiz_score: float|null, completion_rate: float},
     *     backpressure: array<string, mixed>
     * }
     */
    private function aggregate(): array
    {
        $weekAgo = Carbon::now()->subWeek();
        $monthAgo = Carbon::now()->subMonth();

        // `pluck('count', 'role')` sur un Builder Eloquent rejette l'alias
        // `count` côté PHPStan (la colonne doit appartenir au modèle). On
        // matérialise donc via `get()` puis on reconstruit un Collection
        // strictement typé `<string, int>`.
        $byRoleRows = User::selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->get();

        /** @var \Illuminate\Support\Collection<string, int> $usersByRole */
        $usersByRole = $byRoleRows->mapWithKeys(function ($row): array {
            /** @var string $role */
            $role = $row->getAttribute('role');
            /** @var int|string $count */
            $count = $row->getAttribute('count');

            return [$role => (int) $count];
        });

        return [
            // Utilisateurs — BelongsToInstitution global scope filtre par institution_id
            'users' => [
                'total' => User::count(),
                'new_this_week' => User::where('created_at', '>=', $weekAgo)->count(),
                'new_this_month' => User::where('created_at', '>=', $monthAgo)->count(),
                'by_role' => $usersByRole,
            ],

            // Leçons
            'lessons' => [
                'total' => Lesson::count(),
                'published' => Lesson::where('status', 'published')->count(),
                'draft' => Lesson::where('status', 'draft')->count(),
                'created_this_week' => Lesson::where('created_at', '>=', $weekAgo)->count(),
            ],

            // Evaluations
            'evaluations' => [
                'total' => Evaluation::count(),
                'published' => Evaluation::where('status', 'published')->count(),
                'submissions_total' => EvaluationSubmission::count(),
                'submissions_this_week' => EvaluationSubmission::where('submitted_at', '>=', $weekAgo)->count(),
                'pending_grading' => EvaluationSubmission::whereNull('note_sur_20')->count(),
            ],

            // Quiz
            'quizzes' => [
                'total_attempts' => QuizAttempt::count(),
                'attempts_this_week' => QuizAttempt::where('started_at', '>=', $weekAgo)->count(),
                'completed' => QuizAttempt::whereNotNull('completed_at')->count(),
                // `avg()` retourne `float|int|numeric-string|null` selon le driver
                // DB. On normalise systématiquement en `?float` pour produire un
                // JSON stable côté front (jamais de string).
                'average_score' => $this->toNullableFloat(
                    QuizAttempt::whereNotNull('score')->avg('score'),
                ),
            ],

            // Performance globale
            'performance' => [
                'average_evaluation_score' => $this->toNullableFloat(
                    EvaluationSubmission::whereNotNull('note_sur_20')->avg('note_sur_20'),
                ),
                'average_quiz_score' => $this->toNullableFloat(
                    QuizAttempt::whereNotNull('score')->avg('score'),
                ),
                'completion_rate' => $this->calculateCompletionRate(),
            ],

            'backpressure' => [
                'queue' => $this->queueMetrics->snapshot(),
                'klassci' => $this->klassciMetrics->snapshot(),
            ],
        ];
    }

    /**
     * Normalise une valeur agrégée (issue de `Builder::avg()`) en `?float`.
     *
     * `avg()` peut retourner `float|int|numeric-string|null` selon le driver
     * SQL utilisé (MySQL renvoie une string décimale, SQLite un float, …).
     * On force une forme unique pour garantir la stabilité du JSON émis vers
     * le front du dashboard.
     */
    private function toNullableFloat(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * Calcule le taux de completion global (quiz terminés / quiz tentés).
     *
     * BelongsToInstitution global scope filtre automatiquement par
     * institution_id sur les deux requêtes ; division défensive si aucun quiz
     * n'a encore été tenté.
     */
    private function calculateCompletionRate(): float
    {
        $totalAttempts = QuizAttempt::count();
        if ($totalAttempts === 0) {
            return 0.0;
        }

        $completedAttempts = QuizAttempt::whereNotNull('completed_at')->count();

        return round(($completedAttempts / $totalAttempts) * 100, 2);
    }
}
