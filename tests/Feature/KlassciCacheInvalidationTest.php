<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use ReflectionClass;
use Tests\TestCase;

/**
 * CRITICAL-09 — Cache invalidation pattern verification (issue #38).
 *
 * The KlassciProxyService uses a « cache key versioning » pattern :
 *   - Each generated key embeds an invalidation timestamp.
 *   - invalidateCache() bumps that timestamp via Cache::forever().
 *   - On the next read, generateCacheKey() produces a new key
 *     (different timestamp) → cache miss → fresh data fetched.
 *
 * Old entries expire naturally with their TTL. This works with all cache
 * drivers (file, database, redis, memcached) — unlike Cache::tags() which
 * is redis/memcached-only.
 *
 * This test verifies the contract directly. Since generateCacheKey() and
 * invalidateCache() are private, Reflection is used. If the methods are
 * later extracted into a dedicated CacheKeyVersioner class, the test will
 * refactor naturally — the contract (« bump → key changes ») stays.
 */
class KlassciCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private KlassciProxyService $service;
    private ReflectionClass $reflection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'cache-test-inst']);
        app(TenantManager::class)->set($this->institution);

        $this->service    = new KlassciProxyService();
        $this->reflection = new ReflectionClass($this->service);

        // Make sure no leftover invalidation timestamp from previous tests.
        Cache::forget("klassci_cache-test-inst_invalidated_at");
    }

    private function callPrivate(string $method, array $args = []): mixed
    {
        $m = $this->reflection->getMethod($method);
        $m->setAccessible(true);
        return $m->invokeArgs($this->service, $args);
    }

    /**
     * Without invalidation, two successive calls produce the same cache key.
     * (Idempotence of the key generation.)
     */
    public function test_same_inputs_produce_same_cache_key(): void
    {
        $k1 = $this->callPrivate('generateCacheKey', ['endpoint', []]);
        $k2 = $this->callPrivate('generateCacheKey', ['endpoint', []]);

        $this->assertSame($k1, $k2, 'Same endpoint + same params must produce a stable cache key.');
    }

    /**
     * Different params produce different keys (no accidental collisions).
     */
    public function test_different_params_produce_different_keys(): void
    {
        $k1 = $this->callPrivate('generateCacheKey', ['endpoint', ['filter' => 'a']]);
        $k2 = $this->callPrivate('generateCacheKey', ['endpoint', ['filter' => 'b']]);

        $this->assertNotSame($k1, $k2, 'Distinct params must produce distinct cache keys.');
    }

    /**
     * Different tenants produce different keys (no cross-institution leakage).
     */
    public function test_different_tenants_produce_different_keys(): void
    {
        $k1 = $this->callPrivate('generateCacheKey', ['endpoint', []]);

        $otherInstitution = Institution::factory()->create(['slug' => 'other-inst']);
        app(TenantManager::class)->set($otherInstitution);

        $k2 = $this->callPrivate('generateCacheKey', ['endpoint', []]);

        $this->assertNotSame($k1, $k2, 'Different tenants must produce distinct cache keys.');
    }

    /**
     * CORE TEST — calling invalidateCache() changes the next generated key.
     * This is the contract that makes the timestamp pattern actually invalidate.
     */
    public function test_invalidateCache_bumps_the_next_generated_key(): void
    {
        $before = $this->callPrivate('generateCacheKey', ['endpoint', []]);

        // Force at least 1-second resolution between timestamps.
        sleep(1);

        $this->callPrivate('invalidateCache', ['endpoint']);

        $after = $this->callPrivate('generateCacheKey', ['endpoint', []]);

        $this->assertNotSame(
            $before,
            $after,
            'After invalidateCache(), the next generated key must differ — otherwise the cache would keep serving stale data.'
        );
    }

    /**
     * invalidateCache() must actually persist a timestamp in the cache store
     * under the tenant-scoped key.
     */
    public function test_invalidateCache_stores_a_timestamp_in_the_tenant_scoped_key(): void
    {
        $invalidationKey = "klassci_cache-test-inst_invalidated_at";

        $this->assertNull(
            Cache::get($invalidationKey),
            'Sanity check: no timestamp before invalidation.'
        );

        $this->callPrivate('invalidateCache', ['endpoint']);

        $stored = Cache::get($invalidationKey);
        $this->assertNotNull($stored, 'A timestamp must be persisted after invalidation.');
        $this->assertIsNumeric($stored, 'Stored value must be a numeric Unix timestamp.');
        $this->assertGreaterThan(0, (int) $stored);
    }
}
