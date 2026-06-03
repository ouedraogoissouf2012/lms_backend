<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Models\Institution;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Test de connexion KLASSCI pour une institution.
 *
 * Extrait de `InstitutionController` (split `split-12/institution`,
 * §1.6 D strict DI : `HttpFactory` + `LoggerInterface` injectés).
 *
 * Tape l'endpoint public `/auth/check-user` du tenant KLASSCI : ce endpoint
 * accepte n'importe quelle requête (200/422/404) tant que le serveur est
 * joignable, ce qui permet de valider l'URL sans token.
 *
 * @see app/Http/Controllers/API/InstitutionController.php::testConnection()
 */
final class InstitutionConnectionTester
{
    private const HTTP_TIMEOUT_SEC = 10;

    /** Codes considérés comme « serveur joignable et endpoint existant ». */
    private const REACHABLE_STATUSES = [200, 422, 404];

    public function __construct(
        private readonly HttpFactory $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Teste la connexion KLASSCI de l'institution donnée.
     *
     * @return array{status: int, payload: array<string, mixed>}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException
     * @throws \RuntimeException  Si l'URL KLASSCI n'est pas configurée (HTTP 422).
     * @throws \Illuminate\Http\Client\ConnectionException  Serveur injoignable (HTTP 502).
     */
    public function test(int $institutionId): array
    {
        /** @var Institution $institution */
        $institution = Institution::findOrFail($institutionId);
        $config = $institution->getKlassciConfig();

        if (empty($config['url'])) {
            throw new RuntimeException('URL KLASSCI non configurée pour cette institution');
        }

        $url = (string) $config['url'];
        $testUrl = rtrim($url, '/') . '/auth/check-user';

        $client = $this->http->timeout(self::HTTP_TIMEOUT_SEC)
            ->withHeaders(['Accept' => 'application/json']);

        if (!config('services.klassci.ssl_verify', true)) {
            $client = $client->withoutVerifying();
        }

        $startTime = microtime(true);
        $response = $client->post($testUrl, ['identifier' => 'lms-ping-test']);
        $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

        $statusCode = $response->status();

        if (in_array($statusCode, self::REACHABLE_STATUSES, true)) {
            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Connexion KLASSCI réussie',
                    'data' => [
                        'status_code' => $statusCode,
                        'response_time_ms' => $responseTimeMs,
                        'api_url' => $url,
                    ],
                ],
            ];
        }

        $this->logger->warning('KLASSCI connection test returned non-reachable status', [
            'institution_id' => $institutionId,
            'status_code' => $statusCode,
        ]);

        return [
            'status' => 502,
            'payload' => [
                'success' => false,
                'message' => 'Le serveur KLASSCI a répondu avec une erreur (code ' . $statusCode . ')',
                'data' => [
                    'status_code' => $statusCode,
                    'api_url' => $url,
                    'response_time_ms' => $responseTimeMs,
                ],
            ],
        ];
    }
}
