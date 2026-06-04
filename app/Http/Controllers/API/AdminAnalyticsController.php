<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\GetActivityTrendsRequest;
use App\Http\Requests\GetPendingTasksRequest;
use App\Http\Requests\GetRecentUsersRequest;
use App\Http\Requests\GetSystemMetricsRequest;
use App\Services\AdminAnalytics\ActivityTrendsService;
use App\Services\AdminAnalytics\PendingTasksService;
use App\Services\AdminAnalytics\SystemMetricsService;
use Illuminate\Http\JsonResponse;

/**
 * Endpoints REST des analytics administrateur.
 *
 * Thin controller (split `split-23/admin-analytics`) : la logique métier est
 * déléguée à trois services SRP pour respecter §5 (controllers ≤200 l).
 *
 *   - Tendances temporelles → {@see ActivityTrendsService}
 *   - Métriques système     → {@see SystemMetricsService}
 *   - Tâches en attente + derniers users → {@see PendingTasksService}
 *
 * Le contrôleur ne fait plus que :
 *   - Délégation vers le service.
 *   - Validation (via FormRequest).
 *   - Sérialisation JSON (forme de réponse stable côté front).
 *
 * Routes définies dans `routes/api.php` sous le préfixe `/admin/analytics`,
 * protégées par `auth:sanctum` + `role:coordinateur,superAdmin`.
 *
 * ## SECURITY — Isolation cross-tenant (issue #23)
 *
 * Les services préservent la clé de cache scopée par
 * `TenantManager::getResolvedSlug()`. Cf. PHPDoc de chaque service.
 *
 * @see app/Services/AdminAnalytics/ActivityTrendsService.php
 * @see app/Services/AdminAnalytics/SystemMetricsService.php
 * @see app/Services/AdminAnalytics/PendingTasksService.php
 * @see tests/Feature/AdminAnalyticsCacheIsolationTest.php
 */
final class AdminAnalyticsController extends Controller
{
    public function __construct(
        private readonly ActivityTrendsService $activityTrendsService,
        private readonly SystemMetricsService $systemMetricsService,
        private readonly PendingTasksService $pendingTasksService,
    ) {
    }

    /**
     * GET /admin/analytics/activity-trends — Tendances d'activité (30 jours).
     */
    public function getActivityTrends(GetActivityTrendsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->activityTrendsService->getTrends(30),
        ]);
    }

    /**
     * GET /admin/analytics/system-metrics — Compteurs système globaux.
     */
    public function getSystemMetrics(GetSystemMetricsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->systemMetricsService->getMetrics(),
        ]);
    }

    /**
     * GET /admin/analytics/pending-tasks — Compteurs « À faire » pour le
     * widget admin.
     */
    public function getPendingTasks(GetPendingTasksRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->pendingTasksService->getPendingTasks(),
        ]);
    }

    /**
     * GET /admin/analytics/recent-users — 10 derniers utilisateurs inscrits.
     */
    public function getRecentUsers(GetRecentUsersRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->pendingTasksService->getRecentUsers(10),
        ]);
    }
}
