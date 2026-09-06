<?php

declare(strict_types=1);

namespace App\Services\Klassci;

/**
 * La cible d'un pool KLASSCI : tout ce qu'il faut pour bâtir les requêtes d'un
 * lot — URL de base, jeton porteur, et les réglages de transport.
 *
 * ## Pourquoi un objet plutôt que cinq paramètres
 *
 * {@see KlassciResilientPool} rejoue un lot : il doit pouvoir reconstruire les
 * requêtes des identifiants restants. Passer `baseUrl`, `token`,
 * `connectTimeout`, `timeout` et `sslVerify` à chaque tentative, à travers deux
 * niveaux d'appel, produisait des signatures que personne ne relit. Regroupés,
 * ils forment ce qu'ils sont réellement : une cible.
 *
 * Le jeton n'est PAS journalisable — cet objet ne définit volontairement ni
 * `__toString()` ni `jsonSerialize()`.
 */
final class PoolTarget
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $connectTimeout,
        private readonly int $timeout,
        private readonly bool $sslVerify,
    ) {}

    /**
     * Le callback attendu par `Http::pool()` pour ce lot d'identifiants.
     *
     * @param  array<int, array{endpoint: string, memoKey: string, cacheKey: string}>  $batch
     */
    public function requestsFor(array $batch): \Closure
    {
        return function ($pool) use ($batch) {
            $requests = [];

            foreach ($batch as $id => $meta) {
                $url = $this->baseUrl.'/'.ltrim($meta['endpoint'], '/');

                // PR 2 audit `spec-architect` MEDIUM-2 : factorisation HTTP-builder
                // via helper statique pur sur KlassciHttpClient (headers + SSL + token).
                $req = KlassciHttpClient::decorateRequest(
                    $pool->as((string) $id)
                        ->connectTimeout($this->connectTimeout)
                        ->timeout($this->timeout),
                    $url,
                    $this->sslVerify,
                    $this->token,
                );

                $requests[] = $req->get($url);
            }

            return $requests;
        };
    }
}
