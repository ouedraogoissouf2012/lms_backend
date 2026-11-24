<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use App\Exceptions\KlassciUnavailableException;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Le refus d'une réponse KLASSCI en échec (4xx/5xx), extrait tel quel de
 * `KlassciHttpClient::executeHttp` (#1455). La méthode dépassait 40 lignes, ce que le garde de
 * longueur ne voyait pas (une interpolation `"{$x}"` y fermait le corps trop tôt), et le fichier
 * approchait sa limite de 300 lignes.
 */
final class EchecHttpKlassci
{
    /**
     * Frontière 4xx/5xx : à partir de ce status, l'échec est une panne serveur
     * KLASSCI (error) ; en-dessous c'est une réponse client attendue (warning).
     */
    private const SERVER_ERROR_THRESHOLD = 500;

    /**
     * Un 4xx (403 autorisation, 404 introuvable, 429 throttle) est une réponse ATTENDUE côté
     * client, pas une panne : warning, jamais error. Seul un 5xx (panne KLASSCI réelle) reste un
     * error, et compte pour le disjoncteur. Contexte réduit à status + endpoint : le body n'est NI
     * loggé NI exposé dans l'exception (issue #256 : 239 Mo de logs sur des 403/429 répétés avec
     * body complet).
     *
     * @throws KlassciUnavailableException sur un 5xx
     * @throws \RuntimeException sur un 4xx
     */
    public static function rejeter(
        Response $response,
        string $endpoint,
        string $logSuffix,
        LoggerInterface $logger,
        KlassciCircuitBreaker $circuitBreaker,
    ): never {
        $status = $response->status();

        $level = $status >= self::SERVER_ERROR_THRESHOLD ? LogLevel::ERROR : LogLevel::WARNING;
        $logger->log($level, "KLASSCI API Error{$logSuffix}", [
            'status' => $status,
            'endpoint' => $endpoint,
        ]);

        if ($status >= self::SERVER_ERROR_THRESHOLD) {
            $circuitBreaker->reportFailure();
            throw KlassciUnavailableException::upstreamFailure($status);
        }

        throw new \RuntimeException("Erreur API KLASSCI: {$status}", $status);
    }
}
