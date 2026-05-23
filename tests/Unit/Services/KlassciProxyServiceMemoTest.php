<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Klassci\KlassciRequestMemo;
use App\Services\KlassciProxyService;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * PERF-02 (issue #137) — Tests Couches 1+2 de `KlassciProxyService`.
 *
 * Couvre la memoization intra-request et le cache distribué user-token-aware.
 * `Http::fake()` mock le réseau, `Cache::store('array')` fournit un cache
 * in-memory isolé entre tests, `Log::shouldReceive()` valide les non-leaks.
 *
 * @see app/Services/KlassciProxyService.php
 * @see .claude/specs/perf-02-klassci-batch-cache/design.md §2-3
 */
#[CoversClass(KlassciProxyService::class)]
final class KlassciProxyServiceMemoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // App key requise pour que sanctum guard puisse charger sans crash.
        Config::set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        Config::set('cache.default', 'array');
        Config::set('session.driver', 'array');
        Config::set('services.klassci.url', 'https://klassci.test');
        Config::set('services.klassci.token', 'system-token');
        Config::set('services.klassci.memoize_enabled', true);
        Config::set('services.klassci.user_token_cache_default_ttl', 300);

        // Stub TenantManager pour avoir un slug stable dans les clés cache.
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

    public function test_memoizes_identical_get_within_request(): void
    {
        Http::fake([
            'klassci.test/matieres*' => Http::response(['data' => [['id' => 1]]], 200),
        ]);

        $service = app(KlassciProxyService::class);

        $r1 = $service->get('matieres');
        $r2 = $service->get('matieres');
        $r3 = $service->get('matieres');

        self::assertSame($r1, $r2);
        self::assertSame($r2, $r3);
        Http::assertSentCount(1);
    }

    public function test_memoizes_identical_user_token_get_within_request(): void
    {
        Http::fake([
            'klassci.test/matieres/42' => Http::response(['data' => ['id' => 42, 'nom' => 'Maths']], 200),
        ]);

        $service = app(KlassciProxyService::class);

        $r1 = $service->requestWithUserToken('user-token-a', 'matieres/42', 'GET');
        $r2 = $service->requestWithUserToken('user-token-a', 'matieres/42', 'GET');
        $r3 = $service->requestWithUserToken('user-token-a', 'matieres/42', 'GET');

        self::assertSame($r1, $r2);
        self::assertSame($r2, $r3);
        Http::assertSentCount(1);
    }

    public function test_post_clears_request_memo(): void
    {
        // ORDRE IMPORTANT : le pattern plus spécifique en premier (Http::fake match
        // dans l'ordre — le pattern `evaluations*` matcherait aussi `evaluations/1/notes`).
        Http::fake([
            'klassci.test/evaluations/1/notes*' => Http::response(['success' => true], 200),
            'klassci.test/evaluations*'         => Http::response(['data' => ['id' => 1]], 200),
        ]);

        $service = app(KlassciProxyService::class);

        // 1. GET → memoize
        $service->get('evaluations');
        // 2. Second GET → hit memo (no HTTP)
        $service->get('evaluations');
        // 3. POST → invalide + reset memo
        $service->post('evaluations/1/notes', ['notes' => []]);
        // 4. GET → memo vidé, retouche le réseau
        $service->get('evaluations');

        // Total : 1 GET + 1 POST + 1 GET = 3 appels HTTP réels
        Http::assertSentCount(3);
    }

    public function test_user_token_cache_uses_token_hash_in_key(): void
    {
        Http::fake([
            'klassci.test/me/dashboard*' => Http::sequence()
                ->push(['data' => ['user' => 'alice']], 200)
                ->push(['data' => ['user' => 'bob']], 200),
        ]);

        $service = app(KlassciProxyService::class);

        $alice = $service->requestWithUserToken('alice-token', 'me/dashboard', 'GET');
        $bob   = $service->requestWithUserToken('bob-token', 'me/dashboard', 'GET');

        // Tokens distincts → clés cache distinctes → 2 appels HTTP réels.
        self::assertSame(['data' => ['user' => 'alice']], $alice);
        self::assertSame(['data' => ['user' => 'bob']], $bob);
        Http::assertSentCount(2);

        // Re-call avec même tokens → memo hit, 0 appel supplémentaire.
        $service->requestWithUserToken('alice-token', 'me/dashboard', 'GET');
        $service->requestWithUserToken('bob-token', 'me/dashboard', 'GET');
        Http::assertSentCount(2);
    }

    public function test_user_token_post_does_not_read_cache(): void
    {
        Http::fake([
            'klassci.test/evaluations/1/notes*' => Http::response(['success' => true], 200),
        ]);

        $service = app(KlassciProxyService::class);

        // 2 POSTs successifs : doivent TOUS les 2 toucher le réseau (pas de cache write).
        $service->requestWithUserToken('user-token', 'evaluations/1/notes', 'POST', ['notes' => []]);
        $service->requestWithUserToken('user-token', 'evaluations/1/notes', 'POST', ['notes' => []]);

        Http::assertSentCount(2);
    }

    public function test_user_token_post_invalidates_tenant_cache(): void
    {
        // 2 endpoints DISTINCTS pour éviter tout chevauchement de pattern Http::fake.
        Http::fake([
            'klassci.test/structure-write' => Http::response(['success' => true], 200),
            'klassci.test/structure-read'  => Http::sequence()
                ->push(['data' => 'call-1'], 200)
                ->push(['data' => 'call-2'], 200),
        ]);

        $service = app(KlassciProxyService::class);

        // 1. GET → cache + memo
        $first = $service->requestWithUserToken('user-token', 'structure-read', 'GET');
        // 2. POST → invalide tenant (incrémente invalidatedAt + clearRequestMemo)
        $service->requestWithUserToken('user-token', 'structure-write', 'POST', ['payload' => 'x']);

        // 3. GET sur même endpoint : le memo est vidé par le POST + la clé cache change
        //    grâce au nouveau invalidatedAt timestamp → retouche le réseau.
        $second = $service->requestWithUserToken('user-token', 'structure-read', 'GET');

        self::assertSame(['data' => 'call-1'], $first);
        self::assertSame(['data' => 'call-2'], $second);
        // 2 GETs + 1 POST = 3 appels HTTP réels (le 2ᵉ GET ne hit pas le cache car invalidé).
        Http::assertSentCount(3);
    }

    public function test_memoize_disabled_via_config_skips_memo(): void
    {
        // Set la config AVANT app() pour que KlassciRequestMemo lise le flag à
        // l'instanciation (le container Laravel résout par auto-wire à app()).
        Config::set('services.klassci.memoize_enabled', false);

        // Forge un KlassciRequestMemo avec flag explicite OFF et l'enregistre
        // pour que le container l'injecte dans KlassciProxyService.
        $memo = new KlassciRequestMemo(enabled: false);
        $this->app->instance(KlassciRequestMemo::class, $memo);

        Http::fake([
            'klassci.test/matieres*' => Http::response(['data' => []], 200),
        ]);

        $service = app(KlassciProxyService::class);

        $service->get('matieres');
        $service->get('matieres');

        // Sans memo, le 2ᵉ appel devrait toucher le cache distribué (hit après
        // le 1er via Cache::remember). Donc toujours 1 seul HTTP appel — ce qui
        // prouve que la couche cache distribué fonctionne sans dépendre du memo.
        Http::assertSentCount(1);

        // Le memo doit rester vide (flag enabled=false bloque les puts).
        self::assertSame(0, $memo->size());
        self::assertFalse($memo->isEnabled());
    }

    public function test_token_hash_is_not_logged_brutely(): void
    {
        Http::fake([
            'klassci.test/me/dashboard*' => Http::response(['data' => ['user' => 'alice']], 200),
        ]);

        $brutToken = 'super-secret-token-do-not-log';

        // Capture toutes les calls Log::info — vérifie qu'aucune ne contient le token brut.
        $logCalls = [];
        Log::shouldReceive('info')
            ->andReturnUsing(function ($message, $context = []) use (&$logCalls): void {
                $logCalls[] = compact('message', 'context');
            });
        Log::shouldReceive('error')->andReturnNull();
        Log::shouldReceive('warning')->andReturnNull();

        $service = app(KlassciProxyService::class);
        $service->requestWithUserToken($brutToken, 'me/dashboard', 'GET');

        $allLoggedText = (string) json_encode($logCalls);
        self::assertStringNotContainsString(
            $brutToken,
            $allLoggedText,
            'Le user-token brut ne doit JAMAIS apparaître dans les logs (sécurité).',
        );
    }
}
