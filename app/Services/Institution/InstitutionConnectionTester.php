<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Services\Klassci\Health\KlassciReachability;
use Illuminate\Http\Client\ConnectionException;
use Psr\Log\LoggerInterface;

/**
 * Test de connexion KLASSCI (#712) — passe par {@see KlassciReachability},
 * plus d'HTTP brut hors couche client.
 */
final class InstitutionConnectionTester
{
    public function __construct(
        private readonly KlassciReachability $reachability,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    public function test(int $institutionId): array
    {
        $institution = Institution::query()->findOrFail($institutionId);
        $config = $institution->getKlassciConfig();
        $url = is_string($config['url'] ?? null) ? $config['url'] : '';

        if ($url === '') {
            throw new BusinessException('URL KLASSCI non configurée pour cette institution');
        }

        $measure = $this->reachability->probe($url);

        if ($measure->reachable) {
            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Connexion KLASSCI réussie',
                    'data' => [
                        'status_code' => $measure->status,
                        'response_time_ms' => $measure->connectMs,
                        'api_url' => $url,
                    ],
                ],
            ];
        }

        $this->logger->warning('KLASSCI connection test unreachable', [
            'institution_id' => $institutionId,
            'error' => $measure->error,
        ]);

        throw new ConnectionException($measure->error ?? 'KLASSCI injoignable');
    }
}
