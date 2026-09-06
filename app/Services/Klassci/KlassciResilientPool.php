<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * Exécute les lectures KLASSCI en pools parallèles, en survivant au filtrage
 * réseau de l'hébergeur (#744) — et sans l'aggraver.
 *
 * ## Ce que cette classe existe pour empêcher
 *
 * L'hôte de KLASSCI avale les SYN par salves. Rejouer un lot est donc utile :
 * un second essai aboutit en 17 ms (mesuré en production le 2026-09-06).
 *
 * Mais un réessai naïf, imbriqué dans la boucle de lots, **triple le coût d'une
 * panne**. Chiffré sur un chemin SYNCHRONE (un enseignant attend) : 40 classes,
 * lots de 4, cible injoignable à `connect_timeout` = 2 s. Sans borne, 10 lots
 * × (3 essais × 2 s + pauses) ≈ 62 s — au-delà du plafond usuel de PHP-FPM,
 * donc un 504 au lieu d'une page dégradée mais servie.
 *
 * D'où le disjoncteur, ici : trois lots épuisés l'ouvrent, et l'on rend alors
 * ce qui a été obtenu. Le contrat tolère déjà le partiel — un identifiant en
 * échec est simplement absent du tableau rendu.
 *
 * ## Pourquoi le disjoncteur est ALIMENTÉ et pas seulement consulté
 *
 * Le chemin batch l'ignorait entièrement : ses échecs restaient invisibles,
 * si bien que pendant une panne réelle le pool continuait de marteler l'hôte
 * pendant que {@see KlassciHttpClient}, lui, se mettait en retrait. Un seul
 * `reportFailure()` par lot épuisé — jamais un par identifiant, ce qui
 * ouvrirait le disjoncteur sur un unique lot malchanceux.
 */
final class KlassciResilientPool
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly KlassciBatchResponseCollector $collector,
        private readonly KlassciTransportRetry $retry,
        private readonly KlassciCircuitBreaker $circuitBreaker,
    ) {}

    /**
     * Parcourt les lots et remplit `$resolved`. S'arrête net si le disjoncteur
     * s'ouvre en cours de route.
     *
     * @param  array<int, array<int, array{endpoint: string, memoKey: string, cacheKey: string}>>  $chunks
     * @param  array<int, array<string, mixed>>  $resolved
     */
    public function run(array $chunks, PoolTarget $target, int $ttl, array &$resolved): void
    {
        foreach ($chunks as $batch) {
            if ($this->circuitBreaker->isOpen()) {
                return;
            }

            $this->runBatch($batch, $target, $ttl, $resolved);
        }
    }

    /**
     * Exécute un lot, puis REJOUE en un second pool les seuls identifiants
     * tombés au TRANSPORT.
     *
     * Le pool Guzzle ne rejoue pas une requête isolée : il rend un tableau déjà
     * settled. Une seconde passe groupée est aussi la forme la plus économe sur
     * un hôte qui filtre précisément sur le volume. Le tri panne/réponse
     * appartient à {@see KlassciBatchResponseCollector}, testable sans réseau.
     *
     * @param  array<int, array{endpoint: string, memoKey: string, cacheKey: string}>  $batch
     * @param  array<int, array<string, mixed>>  $resolved
     */
    private function runBatch(array $batch, PoolTarget $target, int $ttl, array &$resolved): void
    {
        $restants = $batch;

        // `attemptsFor('GET')` : le pool est exclusivement composé de GET,
        // donc rejouable sans risque d'effet de bord.
        $tentatives = $this->retry->attemptsFor('GET');

        for ($tentative = 1; $tentative <= $tentatives; $tentative++) {
            $responses = $this->http->pool($target->requestsFor($restants));
            $aRejouer = $this->collector->collect($restants, $responses, $ttl, $resolved);

            if ($aRejouer === []) {
                return;
            }

            $restants = array_intersect_key($restants, array_flip($aRejouer));

            if ($tentative < $tentatives) {
                usleep($this->retry->backoffMicroseconds($tentative));
            }
        }

        $this->circuitBreaker->reportFailure();

        foreach ($restants as $id => $meta) {
            $this->collector->logExhaustedTransport($id, $meta);
        }
    }
}
