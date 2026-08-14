<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Enums\LessonStatus;
use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Lesson;
use App\Models\Notification;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Agrégation des statistiques globales admin/coordinateur (issue #364).
 *
 * Extrait verbatim de `DashboardAdminController::stats`. Deux mécanismes
 * d'isolation coexistent, préservés tels quels :
 *
 *   - les modèles de contenu (Lesson, Quiz, QuizAttempt, ForumTopic,
 *     ForumPost, Notification) sont isolés par le scope global
 *     `BelongsToInstitution` (TEST-03 : ne jamais repasser par
 *     `DB::table()` qui le court-circuite) ;
 *   - les Users cumulent ce même scope global ET le filtre manuel par
 *     `klassci_tenant_url` (`when($tenantUrl, ...)` → si l'URL du
 *     coordinateur est null, seul le scope institution subsiste —
 *     comportement hérité, verrouillé par AdminDashboardServiceTest).
 *
 * ## Cache 300s scopé tenant (#546)
 *
 * ~10 `count()` exécutés à chaque appel, alignés désormais sur le pattern
 * de {@see \App\Services\AdminAnalytics\SystemMetricsService} : clé de
 * cache dérivée du **slug d'institution** (`TenantManager::getResolvedSlug()`,
 * fail-secure), jamais `md5(klassci_tenant_url)` seul — régression
 * historique cross-institution documentée sur ce service sœur. Un second
 * segment de clé (`klassci_tenant_url` du coordinateur) est ajouté car,
 * contrairement à `SystemMetricsService`, ce payload varie **par
 * coordinateur au sein d'une même institution** (cf. test
 * `test_url_filter_is_disabled_when_coordinator_has_no_tenant_url`) —
 * volontairement PAS `TenantScopedCache` (no-op de scoping sur le store
 * `database`, cf. #547 non résolu à ce stade).
 *
 * ## SRP / DI (§1.6)
 *
 * Une seule responsabilité : produire le payload de stats globales pour
 * l'utilisateur staff donné, caché. `CacheRepository`/`TenantManager`/
 * `LoggerInterface` injectés par constructeur — jamais de Facade.
 *
 * @see app/Http/Controllers/API/Dashboard/DashboardAdminController.php
 */
final class AdminDashboardService
{
    /**
     * Fenêtre de l'activité récente, en jours.
     */
    private const RECENT_DAYS = 7;

    /**
     * TTL du cache (5 min) — cohérent avec SystemMetricsService/ActivityTrendsService.
     */
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly TenantManager $tenantManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Construire (ou servir depuis le cache) le payload complet des
     * statistiques globales.
     *
     * @return array<string, mixed>
     */
    public function buildStats(User $user): array
    {
        $cacheKey = $this->cacheKey($user);

        $this->logger->debug('admin_dashboard.stats.request', ['cache_key' => $cacheKey]);

        return $this->cache->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->aggregate($user),
        );
    }

    /**
     * Clé de cache : slug d'institution (isolation cross-tenant obligatoire,
     * jamais contournable) + sous-scope `klassci_tenant_url` du coordinateur
     * (isolation intra-institution — ce payload varie par coordinateur).
     * `md5()` ne sert ici QUE de sous-scope à l'intérieur d'une institution
     * déjà isolée par le slug — une collision resterait confinée au même
     * tenant, jamais cross-institution.
     */
    private function cacheKey(User $user): string
    {
        $institution = $this->tenantManager->getResolvedSlug();
        $scope = $user->klassci_tenant_url !== null ? md5($user->klassci_tenant_url) : 'all';

        return "admin_dashboard_stats_{$institution}_{$scope}";
    }

    /**
     * Agrégation effective (verbatim de l'ancien `buildStats`).
     *
     * @return array<string, mixed>
     */
    private function aggregate(User $user): array
    {
        $tenantUrl = $user->klassci_tenant_url;

        return [
            'users' => $this->userCounts($tenantUrl),
            'lessons' => [
                'total' => Lesson::count(),
                'published' => Lesson::where('status', LessonStatus::Published->value)->count(),
            ],
            'quizzes' => [
                'total' => Quiz::count(),
                'total_attempts' => QuizAttempt::where('status', 'completed')->count(),
            ],
            'forum' => [
                'total_topics' => ForumTopic::count(),
                // TEST-03 : utilise le model pour respecter le scope global
                // `BelongsToInstitution`. `DB::table()` court-circuitait Eloquent
                // et fuitait les forum_posts cross-tenant dans le count.
                'total_posts' => ForumPost::count(),
            ],
            'notifications' => [
                'total' => Notification::count(),
                'unread' => Notification::whereNull('read_at')->count(),
            ],
            'recent_activity' => $this->recentActivity($tenantUrl),
        ];
    }

    /**
     * Compteurs users filtrés par l'URL tenant KLASSCI du coordinateur.
     *
     * @return array<string, int>
     */
    private function userCounts(?string $tenantUrl): array
    {
        return [
            'total' => $this->tenantUsersQuery($tenantUrl)->count(),
            'students' => $this->tenantUsersQuery($tenantUrl)->where('role', 'etudiant')->count(),
            'teachers' => $this->tenantUsersQuery($tenantUrl)->where('role', 'enseignant')->count(),
        ];
    }

    /**
     * Activité des 7 derniers jours (créations récentes).
     *
     * @return array<string, int>
     */
    private function recentActivity(?string $tenantUrl): array
    {
        $since = now()->subDays(self::RECENT_DAYS);

        return [
            'new_users' => $this->tenantUsersQuery($tenantUrl)->where('created_at', '>=', $since)->count(),
            'new_lessons' => Lesson::where('created_at', '>=', $since)->count(),
            'new_quiz_attempts' => QuizAttempt::where('created_at', '>=', $since)->count(),
            'new_forum_topics' => ForumTopic::where('created_at', '>=', $since)->count(),
        ];
    }

    /**
     * Base commune des requêtes users : filtre `klassci_tenant_url`
     * uniquement quand le coordinateur en possède une (verbatim `when()`).
     *
     * @return Builder<User>
     */
    private function tenantUsersQuery(?string $tenantUrl): Builder
    {
        return User::when($tenantUrl, fn ($q) => $q->where('klassci_tenant_url', $tenantUrl));
    }
}
