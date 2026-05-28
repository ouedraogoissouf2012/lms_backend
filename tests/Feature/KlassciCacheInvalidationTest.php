<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Institution;
use App\Services\Klassci\KlassciCacheKeyStrategy;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * CRITICAL-09 — Cache invalidation pattern verification (issue #38).
 *
 * Le KlassciCacheKeyStrategy implémente un pattern « cache key versioning » :
 *   - Chaque clé générée incorpore un timestamp d'invalidation.
 *   - `invalidateTenant()` bump ce timestamp via Cache::forever().
 *   - Au prochain lookup, `generateGlobalKey()` produit une nouvelle clé
 *     (timestamp différent) → cache miss → données fraîches.
 *
 * Compatible avec tous les drivers cache (file, database, redis, memcached) —
 * contrairement à `Cache::tags()` qui est redis/memcached-only.
 *
 * ## PERF-02 (issue #137) — Refactor post-split
 *
 * Avant le split architectural de PR 1 PERF-02, `generateCacheKey()` et
 * `invalidateCache()` étaient des méthodes privées de `KlassciProxyService`.
 * Le test devait passer par Reflection. Depuis le split, ces méthodes vivent
 * comme méthodes **publiques** sur `KlassciCacheKeyStrategy` — la Reflection
 * est éliminée, le contract est testé directement.
 *
 * @see \App\Services\Klassci\KlassciCacheKeyStrategy
 */
final class KlassciCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private KlassciCacheKeyStrategy $strategy;

    protected function setUp(): void
    {

        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'cache-test-inst']);
        app(TenantManager::class)->set($this->institution);

        $this->strategy = app(KlassciCacheKeyStrategy::class);

        Cache::forget('klassci_cache-test-inst_invalidated_at');
    }

    /**
     * Without invalidation, two successive calls produce the same cache key.
     * (Idempotence of the key generation.)
     */
    public function test_same_inputs_produce_same_cache_key(): void
    {
        $k1 = $this->strategy->generateGlobalKey('endpoint', []);
        $k2 = $this->strategy->generateGlobalKey('endpoint', []);

        self::assertSame($k1, $k2, 'Same endpoint + same params must produce a stable cache key.');
    }

    /**
     * Different params produce different keys (no accidental collisions).
     */
    public function test_different_params_produce_different_keys(): void
    {
        $k1 = $this->strategy->generateGlobalKey('endpoint', ['filter' => 'a']);
        $k2 = $this->strategy->generateGlobalKey('endpoint', ['filter' => 'b']);

        self::assertNotSame($k1, $k2, 'Distinct params must produce distinct cache keys.');
    }

    /**
     * Different tenants produce different keys (no cross-institution leakage).
     */
    public function test_different_tenants_produce_different_keys(): void
    {
        $k1 = $this->strategy->generateGlobalKey('endpoint', []);

        $otherInstitution = Institution::factory()->create(['slug' => 'other-inst']);
        app(TenantManager::class)->set($otherInstitution);

        $k2 = $this->strategy->generateGlobalKey('endpoint', []);

        self::assertNotSame($k1, $k2, 'Different tenants must produce distinct cache keys.');
    }

    /**
     * CORE TEST — calling invalidateTenant() changes the next generated key.
     * C'est le contract qui fait que le pattern timestamp invalide réellement.
     */
    public function test_invalidate_tenant_bumps_the_next_generated_key(): void
    {
        $before = $this->strategy->generateGlobalKey('endpoint', []);

        // Force at least 1-second resolution between timestamps.
        sleep(1);

        $this->strategy->invalidateTenant('endpoint');

        $after = $this->strategy->generateGlobalKey('endpoint', []);

        self::assertNotSame(
            $before,
            $after,
            'After invalidateTenant(), the next generated key must differ — sinon le cache servirait des données stales.'
        );
    }

    /**
     * invalidateTenant() must actually persist a timestamp in the cache store
     * under the tenant-scoped key.
     */
    public function test_invalidate_tenant_stores_a_timestamp_in_the_tenant_scoped_key(): void
    {
        $invalidationKey = 'klassci_cache-test-inst_invalidated_at';

        self::assertNull(
            Cache::get($invalidationKey),
            'Sanity check: no timestamp before invalidation.'
        );

        $this->strategy->invalidateTenant('endpoint');

        $stored = Cache::get($invalidationKey);
        self::assertNotNull($stored, 'A timestamp must be persisted after invalidation.');
        self::assertIsNumeric($stored, 'Stored value must be a numeric Unix timestamp.');
        self::assertGreaterThan(0, (int) $stored);
    }

    /**
     * PERF-02 — Le `generateUserTokenKey()` doit produire des clés DISTINCTES
     * pour des tokenHash distincts, même endpoint + params + tenant identiques.
     *
     * C'est l'invariant de sécurité d'isolation cross-user (cf. design.md §8.2).
     */
    public function test_user_token_keys_isolate_distinct_token_hashes(): void
    {
        $kA = $this->strategy->generateUserTokenKey('me/dashboard', [], 'hash_alice_1234');
        $kB = $this->strategy->generateUserTokenKey('me/dashboard', [], 'hash_bob_5678');

        self::assertNotSame(
            $kA,
            $kB,
            'Different tokenHash MUST produce distinct cache keys — sinon User A pourrait lire le cache de User B.'
        );
    }
}
