<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use App\Exceptions\KlassciUnavailableException;
use App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors;
use App\Support\Klassci\KlassciClientErrorCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;
use Throwable;

/**
 * Traduction des échecs KLASSCI en réponse HTTP pour les contrôleurs proxy.
 *
 * Défaut corrigé : seul le 401 était traité, si bien qu'un 403 d'AUTORISATION
 * KLASSCI ressortait en 500 « Service indisponible ». Un refus de droits devenait
 * indiscernable d'une panne serveur — pour l'utilisateur, dont le message était
 * faux, comme pour la supervision, qui voyait des 5xx là où il n'y avait aucun
 * incident. Constaté en production locale : les 17 appels
 * /proxy/classes/{id}/etudiants renvoyaient 500 alors que KLASSCI répondait 403.
 */
final class RendersKlassciProxyErrorsTest extends TestCase
{
    private function render(Throwable $error): JsonResponse
    {
        $renderer = new class
        {
            use RendersKlassciProxyErrors;

            public function render(Throwable $error): JsonResponse
            {
                return $this->proxyErrorResponse($error);
            }
        };

        return $renderer->render($error);
    }

    public function test_expired_klassci_session_is_not_reported_as_server_crash(): void
    {
        $response = $this->render(new \RuntimeException('Erreur API KLASSCI: 401', 401));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame([
            'success' => false,
            'message' => 'Session KLASSCI expirée. Veuillez vous reconnecter.',
            'reason' => 'klassci_session_expired',
        ], $response->getData(true));
    }

    /** Le cas qui motive le correctif. */
    public function test_forbidden_is_relayed_as_403_not_as_a_server_error(): void
    {
        $response = $this->render(new \RuntimeException('Erreur API KLASSCI: 403', 403));

        self::assertSame(403, $response->getStatusCode());

        $body = $response->getData(true);
        self::assertFalse($body['success']);
        self::assertSame(KlassciClientErrorCatalog::messageFor(403), $body['message']);

        // `reason` est le contrat de la seule expiration de session : ne pas l'élargir.
        self::assertArrayNotHasKey('reason', $body);
    }

    /** §1.2 — le message technique du client HTTP ne doit jamais ressortir. */
    public function test_relayed_error_never_leaks_the_upstream_technical_message(): void
    {
        $body = $this->render(new \RuntimeException('Erreur API KLASSCI: 403', 403))->getData(true);

        self::assertStringNotContainsString('Erreur API KLASSCI', $body['message']);
        self::assertStringNotContainsString('403', $body['message']);
    }

    /** Verrou anti-fusion : 403 et 404 restent distincts, en statut ET en message. */
    public function test_not_found_stays_distinct_from_forbidden(): void
    {
        $notFound = $this->render(new \RuntimeException('Erreur API KLASSCI: 404', 404));
        $forbidden = $this->render(new \RuntimeException('Erreur API KLASSCI: 403', 403));

        self::assertSame(404, $notFound->getStatusCode());
        self::assertNotSame(
            $forbidden->getData(true)['message'],
            $notFound->getData(true)['message'],
        );
    }

    /**
     * Le quota amont est relayé tel quel. DETTE TRACÉE : l'en-tête `Retry-After`
     * de KLASSCI est perdu par le client HTTP (seul le statut survit), et celui de
     * KlassciUnavailableException décrit un backoff de PANNE, pas une fenêtre de
     * quota — le réutiliser mentirait sur le délai.
     */
    public function test_quota_is_relayed_without_a_misleading_retry_after(): void
    {
        $response = $this->render(new \RuntimeException('Erreur API KLASSCI: 429', 429));

        self::assertSame(429, $response->getStatusCode());
        self::assertFalse($response->headers->has('Retry-After'));
    }

    public function test_unprocessable_entity_is_relayed(): void
    {
        self::assertSame(422, $this->render(new \RuntimeException('x', 422))->getStatusCode());
    }

    /**
     * Verrou d'ORDRE des branches : KlassciUnavailableException est elle aussi une
     * RuntimeException. Testée après un relais de plage non borné, elle sortirait
     * avec son propre code au lieu du 503 canonique, et sans `Retry-After`.
     */
    public function test_klassci_unavailable_wins_over_status_relay(): void
    {
        $response = $this->render(new KlassciUnavailableException('indispo', 403));

        self::assertSame(503, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
    }

    public function test_upstream_server_failure_degrades_to_503(): void
    {
        $response = $this->render(KlassciUnavailableException::upstreamFailure(502));

        self::assertSame(503, $response->getStatusCode());
        self::assertTrue($response->headers->has('Retry-After'));
    }

    public function test_connection_failure_degrades_to_503(): void
    {
        self::assertSame(503, $this->render(new ConnectionException('timeout'))->getStatusCode());
    }

    public function test_internal_failure_without_http_status_stays_500(): void
    {
        $response = $this->render(new \RuntimeException('boom interne'));

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('Service indisponible. Veuillez réessayer.', $response->getData(true)['message']);
    }

    /**
     * Une QueryException porte un SQLSTATE en chaîne et hérite de RuntimeException :
     * sans la garde de type du catalogue, `'42S02'` serait pris pour un statut HTTP
     * et ferait échouer le gestionnaire d'erreurs lui-même.
     */
    public function test_a_database_failure_is_never_relayed_as_an_http_status(): void
    {
        // Exception::getCode() est `final` : on reproduit le cas reel en posant le
        // SQLSTATE sur la propriete `code` heritee, exactement comme le fait PDO.
        $sqlFailure = new \RuntimeException('SQLSTATE[42S02]: Base table not found');
        $codeProperty = new \ReflectionProperty(\Exception::class, 'code');
        $codeProperty->setAccessible(true);
        $codeProperty->setValue($sqlFailure, '42S02');

        self::assertSame('42S02', $sqlFailure->getCode());
        self::assertSame(500, $this->render($sqlFailure)->getStatusCode());
    }

    /** On ne relaie pas le code de n'importe quel Throwable, seulement des RuntimeException KLASSCI. */
    public function test_an_unrelated_exception_carrying_403_is_not_relayed(): void
    {
        self::assertSame(500, $this->render(new \LogicException('bug interne', 403))->getStatusCode());
    }
}
