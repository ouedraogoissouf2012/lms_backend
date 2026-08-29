<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Models\Institution;
use App\Services\Cache\TenantScopedCache;
use App\Services\Cache\TenantScopedCacheInterface;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /**
     * #547 — le store `database` (défaut prod) n'a PAS de tags mais supporte
     * désormais une purge PHYSIQUE ciblée par namespace tenant. `flushTenant()`
     * n'est donc plus un no-op : il supprime les entrées du tenant courant, sans
     * exception, sans flush global de repli (le tenant B est couvert par
     * TenantCacheDatabasePurgeTest).
     */
    public function test_flush_tenant_on_database_store_physically_purges_current_tenant(): void
    {
        config()->set('cache.default', 'database');

        $tenantManager = app(TenantManager::class);
        $tenantManager->set($this->institutionA);

        $cache = app(TenantScopedCacheInterface::class);
        $cache->remember('cle-degradee', 300, fn (): string => 'valeur-persistee');

        // Aucune exception attendue — la purge ciblée ne throw pas.
        $cache->flushTenant();

        // L'entrée du tenant a été physiquement purgée : le callback est
        // ré-exécuté (cache miss), preuve que la purge database n'est plus inerte.
        self::assertSame(
            'valeur-recalculee',
            $cache->remember('cle-degradee', 300, fn (): string => 'valeur-recalculee'),
            'Sur store database, flushTenant() doit purger physiquement : cache miss après flush.'
        );
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
