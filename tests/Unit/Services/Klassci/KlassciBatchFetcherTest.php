<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Services\Klassci\KlassciBatchFetcher;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * PERF-02 (issue #135) — Tests Couche 3 (`KlassciBatchFetcher`).
 *
 * Couvre le batch parallélisé via `Http::pool`, l'intégration memo + cache
 * distribué, et la tolérance aux erreurs partielles (log + omission, pas de
 * throw global).
 *
 * @see app/Services/Klassci/KlassciBatchFetcher.php
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §4
 */
#[CoversClass(KlassciBatchFetcher::class)]
final class KlassciBatchFetcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('cache.default', 'array');
        Config::set('session.driver', 'array');
        Config::set('services.klassci.url', 'https://klassci.test');
        Config::set('services.klassci.token', 'system-token');
        Config::set('services.klassci.pool_size', 4);
        Config::set('services.klassci.memoize_enabled', true);
        Config::set('services.klassci.user_token_cache_default_ttl', 300);

        // Stub TenantManager pour avoir un slug stable.
        $tenantManager = Mockery::mock(TenantManager::class);
        $tenantManager->shouldReceive('slug')->andReturn('school-test');
        $tenantManager->shouldReceive('klassciConfig')->andReturn([
            'url'   => 'https://klassci.test',
            'token' => 'system-token',
        ]);
        $this->app->instance(TenantManager::class, $tenantManager);

        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_fetch_many_returns_map_indexed_by_id(): void
    {
        Http::fake([
            'klassci.test/matieres/1' => Http::response(['data' => ['id' => 1, 'nom' => 'Math']], 200),
            'klassci.test/matieres/2' => Http::response(['data' => ['id' => 2, 'nom' => 'Histoire']], 200),
            'klassci.test/matieres/3' => Http::response(['data' => ['id' => 3, 'nom' => 'Bio']], 200),
        ]);

        $fetcher = app(KlassciBatchFetcher::class);
        $result = $fetcher->fetchManyByEndpoint([1, 2, 3], 'matieres/{id}', 'user-token');

        self::assertCount(3, $result);
        self::assertSame(['data' => ['id' => 1, 'nom' => 'Math']], $result[1]);
        self::assertSame(['data' => ['id' => 2, 'nom' => 'Histoire']], $result[2]);
        self::assertSame(['data' => ['id' => 3, 'nom' => 'Bio']], $result[3]);
    }

    public function test_fetch_many_skips_failed_ids(): void
    {
        Http::fake([
            'klassci.test/matieres/1' => Http::response(['data' => 'ok-1'], 200),
            'klassci.test/matieres/2' => Http::response(['error' => 'not found'], 500),
            'klassci.test/matieres/3' => Http::response(['data' => 'ok-3'], 200),
        ]);

        $fetcher = app(KlassciBatchFetcher::class);
        $result = $fetcher->fetchManyByEndpoint([1, 2, 3], 'matieres/{id}', 'user-token');

        self::assertCount(2, $result);
        self::assertArrayHasKey(1, $result);
        self::assertArrayNotHasKey(2, $result, 'ID 2 (500) doit être absent du map résultat.');
        self::assertArrayHasKey(3, $result);
    }

    public function test_fetch_many_cache_hit_short_circuits_network(): void
    {
        // Round 1 : populate cache distribué pour IDs 1 et 2 (et 3 aussi, mais
        // on instancie un nouveau fetcher au round 2 → memo vidé, seul le cache
        // distribué persiste).
        Http::fake([
            'klassci.test/matieres/1' => Http::response(['data' => 'first-call-1'], 200),
            'klassci.test/matieres/2' => Http::response(['data' => 'first-call-2'], 200),
        ]);

        $fetcher = app(KlassciBatchFetcher::class);
        $fetcher->fetchManyByEndpoint([1, 2], 'matieres/{id}', 'user-token');

        // Round 2 : forge un nouveau fetcher (vide le memo intra-request) et redéfinit
        // Http::fake — IDs 1 et 2 doivent venir du cache distribué, ID 3 du réseau.
        Http::fake([
            'klassci.test/matieres/3' => Http::response(['data' => 'fresh-3'], 200),
        ]);

        $this->app->forgetInstance(KlassciBatchFetcher::class);
        $fetcher2 = app(KlassciBatchFetcher::class);
        $result = $fetcher2->fetchManyByEndpoint([1, 2, 3], 'matieres/{id}', 'user-token');

        self::assertCount(3, $result);
        // IDs 1 et 2 viennent du cache (round 1), ID 3 du round courant.
        self::assertArrayHasKey(3, $result);
        // L'appel HTTP du round 2 ne touche que matieres/3.
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/matieres/3'));
    }

    public function test_fetch_many_memo_hit_short_circuits_cache_and_network(): void
    {
        Http::fake([
            'klassci.test/matieres/1' => Http::response(['data' => 'val-1'], 200),
            'klassci.test/matieres/2' => Http::response(['data' => 'val-2'], 200),
        ]);

        $fetcher = app(KlassciBatchFetcher::class);

        // Premier appel : populate memo + cache.
        $first = $fetcher->fetchManyByEndpoint([1, 2], 'matieres/{id}', 'user-token');

        // Deuxième appel SUR LA MÊME INSTANCE : memo hit, 0 nouvel appel HTTP.
        $second = $fetcher->fetchManyByEndpoint([1, 2], 'matieres/{id}', 'user-token');

        self::assertSame($first, $second);
        // 2 appels HTTP au total (round 1 uniquement) — round 2 = memo hits.
        Http::assertSentCount(2);
    }

    public function test_fetch_many_empty_array_returns_empty_map(): void
    {
        Http::fake();

        $fetcher = app(KlassciBatchFetcher::class);
        $result = $fetcher->fetchManyByEndpoint([], 'matieres/{id}', 'user-token');

        self::assertSame([], $result);
        Http::assertSentCount(0);
    }

    public function test_fetch_many_handles_endpoint_pattern_with_query_string(): void
    {
        Http::fake([
            'klassci.test/classes/5/etudiants*' => Http::response(['data' => ['student-list-5']], 200),
            'klassci.test/classes/6/etudiants*' => Http::response(['data' => ['student-list-6']], 200),
        ]);

        $fetcher = app(KlassciBatchFetcher::class);
        $result = $fetcher->fetchManyClasseEtudiants([5, 6], anneeId: 42);

        self::assertCount(2, $result);
        self::assertSame(['data' => ['student-list-5']], $result[5]);
        self::assertSame(['data' => ['student-list-6']], $result[6]);
    }

    public function test_fetch_many_deduplicates_ids(): void
    {
        Http::fake([
            'klassci.test/matieres/1' => Http::response(['data' => 'val-1'], 200),
        ]);

        $fetcher = app(KlassciBatchFetcher::class);
        $result = $fetcher->fetchManyByEndpoint([1, 1, 1], 'matieres/{id}', 'user-token');

        self::assertCount(1, $result);
        self::assertSame(['data' => 'val-1'], $result[1]);
        // Une seule requête HTTP réelle malgré 3 IDs en doublon.
        Http::assertSentCount(1);
    }

    public function test_fetch_many_uses_distinct_tokens_in_cache_key(): void
    {
        Http::fake([
            'klassci.test/matieres/1' => Http::sequence()
                ->push(['data' => 'for-alice'], 200)
                ->push(['data' => 'for-bob'], 200),
        ]);

        $fetcher = app(KlassciBatchFetcher::class);

        // Alice et Bob ont des tokens distincts → clés cache distinctes → 2 appels HTTP.
        $alice = $fetcher->fetchManyByEndpoint([1], 'matieres/{id}', 'alice-token');
        $bob   = $fetcher->fetchManyByEndpoint([1], 'matieres/{id}', 'bob-token');

        self::assertSame(['data' => 'for-alice'], $alice[1]);
        self::assertSame(['data' => 'for-bob'], $bob[1]);
        Http::assertSentCount(2);
    }
}
