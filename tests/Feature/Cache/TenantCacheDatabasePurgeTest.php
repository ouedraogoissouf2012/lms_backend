<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Models\Institution;
use App\Services\Cache\TenantScopedCacheInterface;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #547 R2/R3 — sur le store `database` (défaut prod, figé dans phpunit.xml),
 * `flushTenant()` doit PHYSIQUEMENT purger les entrées du tenant courant
 * (et d'aucun autre), au lieu d'être un no-op qui laisse des lignes orphelines.
 *
 * On exerce le vrai store `database` (pas de mock) et on inspecte la table
 * `cache` directement — c'est le comportement production qui régressait.
 */
final class TenantCacheDatabasePurgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // #547 — ce cas vérifie la purge PHYSIQUE du store `database` par
        // inspection directe de la table `cache`. La jambe CI `redis` exécute
        // la suite sous CACHE_STORE=redis, où la purge passe par le
        // TaggedCachePurger (couvert par TenantScopedCacheIsolationTest) et
        // aucune ligne n'existe en base — on saute alors ce cas store-spécifique.
        if (! (app('cache')->store()->getStore() instanceof \Illuminate\Cache\DatabaseStore)) {
            self::markTestSkipped('Spécifique au store cache `database`.');
        }
    }

    private function cache(): TenantScopedCacheInterface
    {
        return app(TenantScopedCacheInterface::class);
    }

    private function setTenant(int $id): void
    {
        $institution = new Institution;
        $institution->id = $id;
        app(TenantManager::class)->set($institution);
    }

    private function cacheRowCount(): int
    {
        return DB::table('cache')->count();
    }

    public function test_flush_tenant_physically_deletes_current_tenant_entries(): void
    {
        $this->setTenant(1);
        $this->cache()->remember('rapport_stats', 300, fn (): string => 'valeur-1');

        self::assertGreaterThan(0, $this->cacheRowCount(), 'l\'entrée doit être persistée');

        $this->cache()->flushTenant();

        self::assertSame(0, $this->cacheRowCount(), 'flushTenant doit purger physiquement');
    }

    public function test_purge_does_not_leak_across_numeric_prefix_boundary(): void
    {
        // Frontière critique : purger le tenant 1 ne doit PAS toucher le tenant 11
        // (préfixe numérique ambigu). Le délimiteur `:` et l'échappement du `_`
        // du namespace garantissent que `institution_1:` ne matche pas
        // `institution_11:`.
        $this->setTenant(1);
        $this->cache()->remember('a', 300, fn (): string => 'v1');

        $this->setTenant(11);
        $this->cache()->remember('b', 300, fn (): string => 'v11');

        self::assertSame(2, $this->cacheRowCount());

        $this->setTenant(1);
        $this->cache()->flushTenant();

        // Seul le tenant 1 est purgé ; le tenant 11 survit intact.
        self::assertSame(1, $this->cacheRowCount());

        $this->setTenant(11);
        $recomputed = false;
        $this->cache()->remember('b', 300, function () use (&$recomputed): string {
            $recomputed = true;

            return 'recalcule';
        });
        self::assertFalse($recomputed, 'le tenant 11 ne doit pas être purgé par la purge du tenant 1');
    }

    public function test_flush_tenant_does_not_touch_other_tenants(): void
    {
        // Tenant A écrit puis tenant B écrit.
        $this->setTenant(1);
        $this->cache()->remember('cle_a', 300, fn (): string => 'valeur-a');

        $this->setTenant(2);
        $this->cache()->remember('cle_b', 300, fn (): string => 'valeur-b');

        self::assertSame(2, $this->cacheRowCount());

        // Tenant A purge : seules SES entrées disparaissent.
        $this->setTenant(1);
        $this->cache()->flushTenant();

        self::assertSame(1, $this->cacheRowCount(), 'l\'entrée du tenant B doit survivre');

        // Le tenant B relit sans re-calcul (cache hit) → même valeur.
        $this->setTenant(2);
        $recomputed = false;
        $value = $this->cache()->remember('cle_b', 300, function () use (&$recomputed): string {
            $recomputed = true;

            return 'recalculee';
        });

        self::assertSame('valeur-b', $value);
        self::assertFalse($recomputed, 'le cache du tenant B ne doit pas avoir été purgé');
    }
}
