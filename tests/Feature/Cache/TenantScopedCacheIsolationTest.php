<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Models\Institution;
use App\Services\Cache\TenantScopedCache;
use App\Services\Cache\TenantScopedCacheInterface;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Spec redis-runtime (#374, Requirements 4.2, 4.5, 6.2) — isolation A/B du
 * flush par tag institution, et dégradation sans erreur sur store non-taggable.
 *
 * Style calqué sur AdminAnalyticsCacheIsolationTest (2 Institution::factory()).
 */
final class TenantScopedCacheIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institutionA;

    private Institution $institutionB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);
    }

    /**
     * Le binding conteneur (AppServiceProvider) doit résoudre l'interface vers
     * l'implémentation sans erreur de résolution du constructeur (tâche 2.3).
     */
    public function test_container_resolves_interface_to_implementation(): void
    {
        $resolved = app(TenantScopedCacheInterface::class);

        self::assertInstanceOf(TenantScopedCache::class, $resolved);
    }

    public function test_flush_tenant_purges_only_the_current_institution_tag(): void
    {
        // Store `array` : supporte les tags sans dépendre d'un Redis local.
        config()->set('cache.default', 'array');

        $tenantManager = app(TenantManager::class);
        $cache = app(TenantScopedCacheInterface::class);

        $tenantManager->set($this->institutionA);
        $cache->remember('cle-partagee', 300, fn (): string => 'valeur-A');

        $tenantManager->set($this->institutionB);
        $cache->remember('cle-partagee', 300, fn (): string => 'valeur-B');

        // Flush sous le tenant A uniquement.
        $tenantManager->set($this->institutionA);
        $cache->flushTenant();

        // A purgé : le callback est ré-exécuté (cache miss garanti).
        $tenantManager->set($this->institutionA);
        self::assertSame(
            'valeur-A-fraiche',
            $cache->remember('cle-partagee', 300, fn (): string => 'valeur-A-fraiche'),
            'Après flushTenant() sous A, la clé de A doit être un cache miss.'
        );

        // B intact : la valeur d'origine est toujours servie, callback ignoré.
        $tenantManager->set($this->institutionB);
        self::assertSame(
            'valeur-B',
            $cache->remember('cle-partagee', 300, fn (): string => 'valeur-B-fraiche'),
            'flushTenant() sous A ne doit JAMAIS toucher les entrées de B (isolation cross-tenant).'
        );
    }

    public function test_flush_tenant_on_untaggable_store_does_not_throw_and_leaves_entries(): void
    {
        // Seul moyen d'exercer réellement supportsTags() === false :
        // le store `database` (DatabaseStore n'étend pas TaggableStore).
        config()->set('cache.default', 'database');

        $tenantManager = app(TenantManager::class);

        $tenantManager->set($this->institutionA);
        app(TenantScopedCacheInterface::class)
            ->remember('cle-degradee', 300, fn (): string => 'valeur-persistee');

        Log::shouldReceive('warning')
            ->once()
            ->with('tenant_cache.flush_skipped_unsupported_store', ['tag' => "institution_{$this->institutionA->id}"]);
        // Les autres canaux de log restent permissifs (bruit d'autres composants).
        Log::shouldReceive('info')->zeroOrMoreTimes();
        Log::shouldReceive('debug')->zeroOrMoreTimes();

        // Instance résolue APRÈS la pose du mock : le binding n'est pas un
        // singleton, le constructeur capture donc bien le logger mocké.
        $cache = app(TenantScopedCacheInterface::class);

        // Aucune exception attendue (Requirement 6.2) — un throw ferait échouer le test.
        $cache->flushTenant();

        // Pas de corruption ni de flush global de repli : l'entrée reste lisible.
        self::assertSame(
            'valeur-persistee',
            $cache->remember('cle-degradee', 300, fn (): string => 'valeur-recalculee'),
            'Sur store non-taggable, flushTenant() doit être un no-op : l\'entrée existante reste servie.'
        );
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
