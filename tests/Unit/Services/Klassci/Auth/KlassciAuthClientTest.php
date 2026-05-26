<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci\Auth;

use App\Services\Klassci\Auth\KlassciAuthClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Issue #120 — Tests unit pour KlassciAuthClient.
 *
 * Couvre :
 * - Happy path : login OK retourne le payload
 * - **Garde anti-régression bug** : tenant retourne ConnectionException
 * - Tenant 4xx → null
 * - Payload `success: false` → null
 * - Pas de fuite password dans les logs
 * - SSL désactivé géré correctement
 *
 * @see app/Services/Klassci/Auth/KlassciAuthClient.php
 * @see .claude/specs/auth-controller-refactor/requirements.md REQ-2
 */
#[CoversClass(KlassciAuthClient::class)]
final class KlassciAuthClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_payload_on_successful_login(): void
    {
        Http::fake([
            'tenant.klassci.test/auth/login' => Http::response([
                'success' => true,
                'data'    => [
                    'user'  => ['id' => 1, 'name' => 'Test', 'role' => 'etudiant'],
                    'token' => 'abc123',
                ],
            ], 200),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $client = new KlassciAuthClient(Http::getFacadeRoot(), $logger);

        $payload = $client->attemptLogin('https://tenant.klassci.test', 'user', 'pwd');

        self::assertIsArray($payload);
        self::assertTrue($payload['success']);
        self::assertSame('abc123', $payload['data']['token']);
    }

    /**
     * 🔑 GARDE ANTI-RÉGRESSION du bug pré-fix ligne 172.
     *
     * `Http::post()` peut throw ConnectionException. Avant fix, le code original
     * appelait `->successful()` SANS le try/catch ni le check `instanceof Response`.
     */
    public function test_returns_null_on_connection_exception_without_crashing(): void
    {
        // Mock direct car Http::fake() ne supporte pas ConnectionException dans le post() direct
        $http = Mockery::mock(HttpFactory::class);
        $pendingRequest = Mockery::mock(\Illuminate\Http\Client\PendingRequest::class);

        $http->shouldReceive('timeout')->andReturn($pendingRequest);
        $pendingRequest->shouldReceive('withHeaders')->andReturnSelf();
        $pendingRequest->shouldReceive('withoutVerifying')->andReturnSelf();
        $pendingRequest->shouldReceive('post')
            ->andThrow(new ConnectionException('Tenant down'));

        $logger = Mockery::spy(LoggerInterface::class);
        $client = new KlassciAuthClient($http, $logger);

        $result = $client->attemptLogin('https://down.klassci.test', 'user', 'pwd');

        self::assertNull($result);

        // Logger doit avoir capturé le warning sans leak du password
        $logger->shouldHaveReceived('warning')
            ->with('KLASSCI tenant connection failed during login', Mockery::on(function ($context) {
                return ($context['tenant_url'] ?? null) === 'https://down.klassci.test'
                    && !isset($context['password']);
            }))->once();
    }

    public function test_returns_null_on_4xx_response(): void
    {
        Http::fake([
            'tenant.klassci.test/auth/login' => Http::response(['message' => 'Invalid credentials'], 401),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $client = new KlassciAuthClient(Http::getFacadeRoot(), $logger);

        $result = $client->attemptLogin('https://tenant.klassci.test', 'user', 'wrong');

        self::assertNull($result);
    }

    public function test_returns_null_when_payload_success_false(): void
    {
        Http::fake([
            'tenant.klassci.test/auth/login' => Http::response([
                'success' => false,
                'message' => 'Account inactive',
            ], 200),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $client = new KlassciAuthClient(Http::getFacadeRoot(), $logger);

        $result = $client->attemptLogin('https://tenant.klassci.test', 'user', 'pwd');

        self::assertNull($result);
    }

    public function test_does_not_log_password(): void
    {
        Http::fake([
            'tenant.klassci.test/auth/login' => Http::response(['message' => 'Invalid'], 401),
        ]);

        $loggedMessages = [];
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('warning', 'info', 'error')
            ->andReturnUsing(function ($message, $context) use (&$loggedMessages): void {
                $loggedMessages[] = ['message' => $message, 'context' => $context];
            });

        $client = new KlassciAuthClient(Http::getFacadeRoot(), $logger);
        $client->attemptLogin('https://tenant.klassci.test', 'user', 'SecretPassword2026!');

        $allLogged = json_encode($loggedMessages);
        self::assertStringNotContainsString('SecretPassword2026', $allLogged ?: '');
    }

    public function test_handles_ssl_disabled_correctly(): void
    {
        Config::set('services.klassci.ssl_verify', false);

        Http::fake([
            'unsigned.klassci.test/auth/login' => Http::response([
                'success' => true,
                'data'    => ['user' => ['id' => 1], 'token' => 'tok'],
            ], 200),
        ]);

        $logger = Mockery::spy(LoggerInterface::class);
        $client = new KlassciAuthClient(Http::getFacadeRoot(), $logger);

        $result = $client->attemptLogin('https://unsigned.klassci.test', 'user', 'pwd');

        self::assertIsArray($result);
        self::assertTrue($result['success']);
    }
}
