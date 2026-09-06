<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Response;
use Psr\Log\LoggerInterface;

/**
 * Trie les réponses d'un pool KLASSCI : ce qui est acquis, ce qui est perdu, et
 * ce qui mérite un second essai (#744).
 *
 * ## Les deux échecs que le pool confondait
 *
 * `KlassciBatchFetcher` traitait identiquement — log `error`, identifiant
 * abandonné — deux situations sans rapport :
 *
 * - une **panne de transport** : le SYN a été avalé par le filtrage de
 *   l'hébergeur de KLASSCI. C'est transitoire ; un second essai aboutit en
 *   17 ms (mesuré depuis la production le 2026-09-06) ;
 * - une **réponse HTTP d'erreur** : KLASSCI a parlé. La rejouer ne changerait
 *   rien, et doublerait la charge sur un hôte qui filtre déjà sur le volume.
 *
 * Conséquence de la confusion : un SYN avalé pendant le pool `classes/{id}`
 * faisait afficher un effectif de classe à **0**, silencieusement.
 *
 * ## Comment on les distingue
 *
 * Vérifié dans Laravel : `PendingRequest::promise()` intercepte le rejet et
 * **retourne** l'exception comme valeur
 * (`->otherwise(function (Throwable $e) { … return $exception; })`). Le tableau
 * du pool contient donc un `ConnectionException` là où une `Response` était
 * attendue. Tout ce qui n'est pas une `Response` est un échec de transport ;
 * une `Response` non-OK est une réponse.
 *
 * ## Pourquoi cette classe existe
 *
 * Elle rend ce tri vérifiable **sans réseau** — un test fabrique directement
 * l'objet d'exception — alors que le seul test du dépôt qui traverse réellement
 * le transport se saute sous Windows. Elle garde en outre
 * {@see KlassciBatchFetcher} sous la garde des 300 lignes.
 */
final class KlassciBatchResponseCollector
{
    /** Frontière 4xx/5xx, identique à {@see KlassciHttpClient} (#256). */
    private const SERVER_ERROR_THRESHOLD = 500;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly KlassciRequestMemo $memo,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Range les réponses d'un lot et rend les identifiants dont l'échec est
     * TRANSITOIRE, donc rejouables.
     *
     * @param  array<int, array{endpoint: string, memoKey: string, cacheKey: string}>  $batch
     * @param  array<string, mixed>  $responses
     * @param  array<int, array<string, mixed>>  $resolved
     * @return list<int>
     */
    public function collect(array $batch, array $responses, int $ttl, array &$resolved): array
    {
        $aRejouer = [];

        foreach ($batch as $id => $meta) {
            $response = $responses[(string) $id] ?? null;

            if (! $response instanceof Response) {
                // Pas de `Response` : la requête n'a pas abouti au transport.
                // Rejouable — et surtout PAS journalisé en `error` ici : ce n'est
                // un incident que si le second essai échoue aussi.
                $aRejouer[] = $id;

                continue;
            }

            if (! $response->ok()) {
                $this->logHttpFailure($id, $meta['endpoint'], $response->status());

                continue;
            }

            $payload = $response->json();
            if (! is_array($payload)) {
                $payload = [];
            }

            /** @var array<string, mixed> $payload */
            $this->cache->put($meta['cacheKey'], $payload, $ttl);
            $this->memo->put($meta['memoKey'], $payload);
            $resolved[$id] = $payload;
        }

        return $aRejouer;
    }

    /**
     * Un identifiant définitivement perdu après le second essai. Journalisé
     * ici, une seule fois, avec le motif de transport.
     *
     * @param  array{endpoint: string, memoKey: string, cacheKey: string}  $meta
     */
    public function logExhaustedTransport(int $id, array $meta): void
    {
        $this->logger->error('KLASSCI batch fetch failed', [
            'id' => $id,
            'endpoint' => $meta['endpoint'],
            'status' => 'no-response',
        ]);
    }

    /**
     * #256 — un 4xx (403 autorisation, 404 introuvable, 429 throttle) est une
     * réponse ATTENDUE côté client : `warning`. Seul un 5xx est une panne
     * KLASSCI réelle. Les journaliser tous en `error` avait produit 239 Mo de
     * logs.
     */
    private function logHttpFailure(int $id, string $endpoint, int $status): void
    {
        $this->logger->log(
            $status >= self::SERVER_ERROR_THRESHOLD ? 'error' : 'warning',
            'KLASSCI batch fetch failed',
            ['id' => $id, 'endpoint' => $endpoint, 'status' => $status],
        );
    }
}
