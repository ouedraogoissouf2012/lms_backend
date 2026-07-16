<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;
use Throwable;

final class RendersKlassciProxyErrorsTest extends TestCase
{
    public function test_expired_klassci_session_is_not_reported_as_server_crash(): void
    {
        $renderer = new class
        {
            use RendersKlassciProxyErrors;

            public function render(Throwable $error): JsonResponse
            {
                return $this->proxyErrorResponse($error);
            }
        };

        $response = $renderer->render(new \RuntimeException('Erreur API KLASSCI: 401', 401));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([
            'success' => false,
            'message' => 'Session KLASSCI expirée. Veuillez vous reconnecter.',
            'reason' => 'klassci_session_expired',
        ], $response->getData(true));
    }
}
