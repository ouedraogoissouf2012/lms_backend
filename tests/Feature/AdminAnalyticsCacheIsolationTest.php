<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Issue #23 — Cache analytics admin doit être strictement isolé par
 * institution (slug LMS), JAMAIS via md5(klassci_tenant_url).
 *
 * Le bug historique :
 *   $cacheKey = 'admin_analytics_system_metrics_' . md5($tenantUrl)
 * Deux écoles partageant le même serveur KLASSCI → même md5 → fuite
 * cross-institution. La correction utilise le slug du TenantManager
 * (unique par école par construction).
 *
 * Voir :
 *   - app/Http/Controllers/API/AdminAnalyticsController.php (méthodes cachées)
 *   - app/Services/TenantManager.php::getResolvedSlug() (fail-secure)
 *   - commit 9a07d04 (24 mars 2026) — fix originale
 *   - OWASP A01:2021 Broken Access Control (cross-tenant data leak)
 */
class AdminAnalyticsCacheIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institutionA;
    private Institution $institutionB;
    private User $coordinatorA;
    private User $coordinatorB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);

        $this->coordinatorA = User::factory()->create([
            'institution_id' => $this->institutionA->id,
            'role'           => 'coordinateur',
        ]);
        $this->coordinatorB = User::factory()->create([
            'institution_id' => $this->institutionB->id,
            'role'           => 'coordinateur',
        ]);

        // Sanity : clean state, no residual cache from another test class.
        Cache::flush();
    }

    /**
     * The cache key MUST follow the format `admin_*_<slug>`.
     * Detects a regression toward `md5($tenantUrl)` or any other format.
     */
    public function test_cache_key_uses_institution_slug_format(): void
    {
        $this->callSystemMetricsAs($this->coordinatorA);

        $this->assertTrue(
            Cache::has('admin_analytics_system_metrics_school-a'),
            'Expected cache key `admin_analytics_system_metrics_school-a`. '
            . 'A regression toward md5(tenant_url) would have created a different key.'
        );
    }

    /**
     * Two institutions must produce TWO distinct cache keys after each
     * has queried the same endpoint. The store must hold both side by side.
     */
    public function test_institutions_use_distinct_cache_keys(): void
    {
        $this->callSystemMetricsAs($this->coordinatorA);
        $this->callSystemMetricsAs($this->coordinatorB);

        $this->assertTrue(
            Cache::has('admin_analytics_system_metrics_school-a'),
            'Institution A must have its own cache key.'
        );
        $this->assertTrue(
            Cache::has('admin_analytics_system_metrics_school-b'),
            'Institution B must have its own cache key.'
        );
    }

    /**
     * Multi-tenant fail-safe : ajouter des étudiants seulement dans A,
     * appeler A puis B. Le total d'utilisateurs de B ne doit pas être
     * pollué par A — même si A a chargé le cache en premier.
     */
    public function test_cache_does_not_leak_user_count_between_institutions(): void
    {
        // 3 students extra in A → A has 4 users total (coordinator + 3).
        User::factory()->count(3)->create([
            'institution_id' => $this->institutionA->id,
            'role'           => 'etudiant',
        ]);
        // B has only its coordinator → 1 user total.

        $responseA = $this->callSystemMetricsAs($this->coordinatorA);
        $responseB = $this->callSystemMetricsAs($this->coordinatorB);

        $this->assertSame(
            4,
            $responseA->json('data.users.total'),
            'Institution A should see its own 4 users.'
        );
        $this->assertSame(
            1,
            $responseB->json('data.users.total'),
            'Institution B must see only its own 1 user — NO leakage from A\'s cache.'
        );
    }

    /**
     * Anti-régression directe : si quelqu'un remet
     * `md5($tenantUrl)` dans la clé de cache, ce test échoue car la
     * clé attendue (basée sur le slug) ne sera pas créée.
     */
    public function test_md5_tenant_url_key_format_is_never_used(): void
    {
        $this->callSystemMetricsAs($this->coordinatorA);

        $regressionKey = 'admin_analytics_system_metrics_'
            . md5($this->coordinatorA->klassci_tenant_url ?? 'all');

        $this->assertFalse(
            Cache::has($regressionKey),
            "Issue #23 regression detected : cache key uses md5(tenant_url) ($regressionKey). "
            . 'Must use TenantManager::getResolvedSlug() instead.'
        );
    }

    /**
     * Helper : authentifie via un Bearer token Sanctum et appelle l'endpoint
     * /api/admin/analytics/system-metrics. Retourne la réponse pour assertions.
     */
    private function callSystemMetricsAs(User $user): \Illuminate\Testing\TestResponse
    {
        $token = $user->createToken('cache-isolation-test')->plainTextToken;

        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/admin/analytics/system-metrics');
    }
}
