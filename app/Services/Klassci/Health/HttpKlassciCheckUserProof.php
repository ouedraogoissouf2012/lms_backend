<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * POST `/auth/check-user` : JSON avec clé `data` + statut 200/422 = KLASSCI.
 * Un 404 HTML n'en est pas (#713).
 */
final class HttpKlassciCheckUserProof implements KlassciApplicationProof
{
    private const TIMEOUT_SEC = 10;

    /** @var list<int> */
    private const KLASSCI_STATUSES = [200, 422];

    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    public function verify(string $baseUrl): ApplicationProofResult
    {
        $url = rtrim($baseUrl, '/').'/auth/check-user';
        $start = microtime(true);

        try {
            $client = $this->http->timeout(self::TIMEOUT_SEC)
                ->withHeaders(['Accept' => 'application/json']);
            if (! config('services.klassci.ssl_verify', true)) {
                $client = $client->withoutVerifying();
            }
            $response = $client->post($url, ['identifier' => 'lms-ping-test']);
        } catch (ConnectionException) {
            return ApplicationProofResult::klassciError(0, 0);
        }

        $elapsed = (int) round((microtime(true) - $start) * 1000);
        $status = $response->status();
        $json = $response->json();

        if ($this->looksLikeCheckUser($status, $json)) {
            return ApplicationProofResult::ok($status, $elapsed);
        }

        if ($status >= 500) {
            return ApplicationProofResult::klassciError($status, $elapsed);
        }

        return ApplicationProofResult::notKlassci($status, $elapsed);
    }

    private function looksLikeCheckUser(int $status, mixed $json): bool
    {
        if (! in_array($status, self::KLASSCI_STATUSES, true)) {
            return false;
        }

        return is_array($json) && array_key_exists('data', $json) && is_array($json['data']);
    }
}
