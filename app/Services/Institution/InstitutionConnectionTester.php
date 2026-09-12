<?php

declare(strict_types=1);

namespace App\Services\Institution;

use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Services\Klassci\Health\ApplicationProofResult;
use App\Services\Klassci\Health\KlassciApplicationProof;
use App\Services\Klassci\Health\KlassciReachability;
use Psr\Log\LoggerInterface;

/**
 * Test de connexion pour le bouton d'administration (#713).
 *
 * La sonde transport ({@see KlassciReachability}) peut juger un 404 « joignable ».
 * Ici on exige en plus une preuve applicative ({@see KlassciApplicationProof}) :
 * JSON `/auth/check-user` avec clé `data`. Une page parquée n'est pas KLASSCI.
 */
final class InstitutionConnectionTester
{
    public function __construct(
        private readonly KlassciReachability $reachability,
        private readonly KlassciApplicationProof $proof,
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
        if (! $measure->reachable) {
            return $this->fail('injoignable', 'Serveur injoignable', $url, 0, $measure->connectMs ?? 0);
        }

        return $this->fromProof($url, $this->proof->verify($url));
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function fromProof(string $url, ApplicationProofResult $proof): array
    {
        if ($proof->isOk()) {
            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'message' => 'Connexion KLASSCI réussie',
                    'data' => [
                        'status_code' => $proof->statusCode,
                        'response_time_ms' => $proof->elapsedMs,
                        'api_url' => $url,
                    ],
                ],
            ];
        }

        $this->logger->warning('KLASSCI connection test applicative proof failed', [
            'kind' => $proof->kind,
            'status_code' => $proof->statusCode,
        ]);

        $message = $proof->kind === 'klassci_erreur'
            ? 'KLASSCI a répondu en erreur'
            : 'Joignable mais ce n\'est pas KLASSCI';

        return $this->fail($proof->kind, $message, $url, $proof->statusCode, $proof->elapsedMs);
    }

    /**
     * @return array{status: int, payload: array<string, mixed>}
     */
    private function fail(string $reason, string $message, string $url, int $statusCode, int $elapsedMs): array
    {
        return [
            'status' => 502,
            'payload' => [
                'success' => false,
                'message' => $message,
                'reason' => $reason,
                'data' => [
                    'status_code' => $statusCode,
                    'response_time_ms' => $elapsedMs,
                    'api_url' => $url,
                ],
            ],
        ];
    }
}
