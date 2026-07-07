<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Klassci;

use App\Exceptions\KlassciUnavailableException;
use App\Services\Klassci\KlassciConfigResolver;
use App\Services\Klassci\KlassciHttpClient;
use App\Services\TenantManager;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Mockery;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Issue #256 — Sur-logging KLASSCI.
 *
 * En prod, `storage/logs/laravel.log` avait atteint 239 Mo : 19 581 entrées 429
 * et 516 entrées 403 loggées au niveau `error`, chacune avec le body KLASSCI
 * complet, et chacune DEUX fois (branche `failed()` + `catch` fourre-tout).
 *
 * Un 4xx KLASSCI (403 autorisation refusée, 404 introuvable, 429 throttle) est
 * une réponse ATTENDUE côté client, pas une panne serveur : il doit être loggé
 * en `warning`, sans body, une seule fois. Seul un 5xx (panne KLASSCI réelle)
 * et une panne transport (KLASSCI injoignable) restent des `error`.
 *
 * @see app/Services/Klassci/KlassciHttpClient.php
 */
#[CoversClass(KlassciHttpClient::class)]
final class KlassciHttpClientTest extends TestCase
{
    private const BASE_URL = 'https://klassci.test';

    /** Body sentinelle : ne doit JAMAIS finir dans un log ni un message d'exception. */
    private const SECRET_BODY_MARKER = 'body-secret-leak-marker-xyz';

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_403_is_logged_as_warning_not_error(): void
    {
        $logger = new RecordingLogger();
        $client = $this->makeClient($logger, status: 403);

        $this->callAndExpectFailure($client);

        self::assertCount(0, $logger->recordsAtLevel(LogLevel::ERROR), 'Un 403 ne doit produire aucun log error.');
        self::assertCount(1, $logger->recordsForMessage('KLASSCI API Error'), 'Un seul log d\'échec (pas de double-log).');

        $record = $logger->recordsForMessage('KLASSCI API Error')[0];
        self::assertSame(LogLevel::WARNING, $record['level']);
        self::assertSame(403, $record['context']['status']);
        self::assertSame('etudiants/me', $record['context']['endpoint']);
    }

    public function test_429_is_logged_as_warning_not_error(): void
    {
        $logger = new RecordingLogger();
        $client = $this->makeClient($logger, status: 429);

        $this->callAndExpectFailure($client);

        self::assertCount(0, $logger->recordsAtLevel(LogLevel::ERROR), 'Un 429 (throttle) ne doit produire aucun log error.');
        self::assertCount(1, $logger->recordsAtLevel(LogLevel::WARNING));
    }

    public function test_5xx_stays_logged_as_error(): void
    {
        $logger = new RecordingLogger();
        $client = $this->makeClient($logger, status: 503);

        $this->callAndExpectFailure($client);

        self::assertCount(1, $logger->recordsAtLevel(LogLevel::ERROR), 'Un 5xx (panne KLASSCI) reste un error.');
        self::assertSame(503, $logger->recordsForMessage('KLASSCI API Error')[0]['context']['status']);
    }

    public function test_5xx_is_exposed_as_retryable_klassci_unavailable(): void
    {
        $client = $this->makeClient(new RecordingLogger(), status: 503);

        $this->expectException(KlassciUnavailableException::class);

        $client->executeHttp('GET', 'etudiants/me');
    }

    public function test_failure_log_context_never_contains_response_body(): void
    {
        $logger = new RecordingLogger();
        $client = $this->makeClient($logger, status: 403);

        $this->callAndExpectFailure($client);

        foreach ($logger->records as $record) {
            self::assertArrayNotHasKey('body', $record['context'], 'Le body KLASSCI ne doit jamais être loggé (#256).');
            self::assertStringNotContainsString(
                self::SECRET_BODY_MARKER,
                json_encode($record['context']) ?: '',
                'Aucune trace du body dans le contexte de log.'
            );
        }
    }

    public function test_exception_message_never_contains_response_body(): void
    {
        $logger = new RecordingLogger();
        $client = $this->makeClient($logger, status: 403);

        try {
            $client->executeHttp('GET', 'etudiants/me');
            self::fail('Un 403 doit lever une exception.');
        } catch (\Throwable $e) {
            self::assertStringNotContainsString(
                self::SECRET_BODY_MARKER,
                $e->getMessage(),
                'Le message d\'exception ne doit pas exposer le body KLASSCI.'
            );
            self::assertStringContainsString('403', $e->getMessage(), 'Le status reste présent pour le diagnostic.');
        }
    }

    public function test_transport_failure_is_logged_as_error(): void
    {
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        $factory->fake(function () {
            throw new ConnectionException('cURL error 28: timeout');
        });

        $client = new KlassciHttpClient($factory, $this->configResolver(), $logger);

        try {
            $client->executeHttp('GET', 'etudiants/me');
            self::fail('Une panne transport doit se propager.');
        } catch (ConnectionException) {
            // attendu
        }

        self::assertCount(1, $logger->recordsAtLevel(LogLevel::ERROR), 'Une panne transport (KLASSCI injoignable) reste un error.');
    }

    public function test_successful_response_returns_decoded_array(): void
    {
        $logger = new RecordingLogger();
        $factory = new HttpFactory();
        $factory->fake(fn () => HttpFactory::response(['success' => true, 'data' => [1, 2]], 200));

        $client = new KlassciHttpClient($factory, $this->configResolver(), $logger);
        $result = $client->executeHttp('GET', 'etudiants/me');

        self::assertSame(['success' => true, 'data' => [1, 2]], $result);
        self::assertCount(0, $logger->recordsAtLevel(LogLevel::ERROR));
        self::assertCount(0, $logger->recordsAtLevel(LogLevel::WARNING));
    }

    public function test_retry_after_config_is_sanitized(): void
    {
        config(['services.klassci.retry_after' => '0']);

        self::assertSame(1, KlassciUnavailableException::retryAfterSeconds());

        config(['services.klassci.retry_after' => '12']);

        self::assertSame(12, KlassciUnavailableException::retryAfterSeconds());
    }

    private function makeClient(LoggerInterface $logger, int $status): KlassciHttpClient
    {
        $factory = new HttpFactory();
        $factory->fake(fn () => HttpFactory::response(
            ['message' => 'rejected', 'secret' => self::SECRET_BODY_MARKER],
            $status,
        ));

        return new KlassciHttpClient($factory, $this->configResolver(), $logger);
    }

    /**
     * Vrai `KlassciConfigResolver` (classe finale → non mockable) avec collaborateurs
     * stubés. Sans utilisateur authentifié, la résolution tombe en priorité 3 (token
     * système via {@see TenantManager::klassciConfig()}), ce qui fournit baseUrl + token.
     */
    private function configResolver(): KlassciConfigResolver
    {
        $guard = Mockery::mock(Guard::class);
        $guard->shouldReceive('user')->andReturn(null);

        $auth = Mockery::mock(AuthFactory::class);
        $auth->shouldReceive('guard')->with('sanctum')->andReturn($guard);

        $tenant = Mockery::mock(TenantManager::class);
        $tenant->shouldReceive('klassciConfig')->andReturn([
            'url'   => self::BASE_URL,
            'token' => 'system-token',
        ]);

        return new KlassciConfigResolver($auth, $tenant, new NullLogger());
    }

    private function callAndExpectFailure(KlassciHttpClient $client): void
    {
        try {
            $client->executeHttp('GET', 'etudiants/me');
            self::fail('Une réponse en échec doit lever une exception.');
        } catch (\Throwable) {
            // attendu — le sujet du test est le LOGGING, pas l'exception elle-même.
        }
    }
}

/**
 * Logger en mémoire (test double substituable — LSP) : enregistre chaque appel
 * `log()` pour permettre des assertions précises sur le niveau et le contexte.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level'   => $level,
            'message' => (string) $message,
            'context' => $context,
        ];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public function recordsAtLevel(string $level): array
    {
        return array_values(array_filter($this->records, fn (array $r): bool => $r['level'] === $level));
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<string, mixed>}>
     */
    public function recordsForMessage(string $needle): array
    {
        return array_values(array_filter($this->records, fn (array $r): bool => str_contains($r['message'], $needle)));
    }
}
