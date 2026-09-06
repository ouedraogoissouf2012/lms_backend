<?php

declare(strict_types=1);

namespace App\Services\Klassci\Health;

use GuzzleHttp\TransferStats;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Mesure réelle de joignabilité (#744) — la seule classe du lot qui touche au
 * réseau.
 *
 * ## Ce qu'elle relève, et pourquoi
 *
 * Le temps de **connexion**, via `on_stats` de Guzzle. C'est la donnée qui
 * tranche : l'hébergeur de KLASSCI avale les SYN par salves, si bien qu'un
 * `connect` expiré révèle une règle de pare-feu là où n'importe quel code HTTP
 * — 404 compris — prouve au contraire que le serveur répond.
 *
 * ## Pourquoi un 404 n'est pas un échec ici
 *
 * La sonde interroge la racine de l'API. Le statut renvoyé n'a aucune
 * importance : on ne teste pas une route, on teste un chemin réseau. Traiter un
 * 404 comme une panne produirait un relevé faux, et un dossier irrecevable.
 *
 * ## Délais volontairement courts
 *
 * Une sonde qui attendrait le délai nominal figerait le planificateur derrière
 * chaque cible filtrée. Un SYN avalé se constate en quelques secondes ; au-delà,
 * la réponse est déjà « injoignable ».
 */
final class HttpKlassciReachability implements KlassciReachability
{
    private const CONNECT_TIMEOUT_SECONDS = 4;

    private const TOTAL_TIMEOUT_SECONDS = 8;

    private readonly bool $sslVerify;

    public function __construct(
        private readonly HttpFactory $http,
    ) {
        // MÊME politique SSL que KlassciHttpClient, délibérément.
        //
        // Trouvé en exécutant la sonde pour de vrai : sans cet alignement, un
        // poste dont le magasin de certificats est incomplet fait rendre
        // « cURL error 60 » — donc « injoignable » — alors que l'application
        // atteint parfaitement la même cible. Un relevé qui contredit le
        // comportement réel du produit est pire qu'aucun relevé : il rendrait
        // le dossier soumis à l'hébergeur irrecevable.
        $this->sslVerify = (bool) config('services.klassci.ssl_verify', true);
    }

    public function probe(string $baseUrl): ReachabilityMeasure
    {
        $connectSeconds = null;

        try {
            $request = $this->http
                ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TOTAL_TIMEOUT_SECONDS)
                ->withOptions([
                    'on_stats' => function (TransferStats $stats) use (&$connectSeconds): void {
                        $mesure = $stats->getHandlerStat('connect_time');
                        $connectSeconds = is_numeric($mesure) ? (float) $mesure : null;
                    },
                ]);

            if (! $this->sslVerify) {
                $request = $request->withoutVerifying();
            }

            $response = $request->get(rtrim($baseUrl, '/'));
        } catch (ConnectionException $e) {
            return ReachabilityMeasure::unreachable($e->getMessage());
        }

        return ReachabilityMeasure::reached(
            (int) round(($connectSeconds ?? 0.0) * 1000),
            $response->status(),
        );
    }
}
