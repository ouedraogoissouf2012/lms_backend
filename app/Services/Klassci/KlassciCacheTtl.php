<?php

declare(strict_types=1);

namespace App\Services\Klassci;

use App\Exceptions\KlassciUnavailableException;

/**
 * Combien de temps garder en cache une réponse KLASSCI 2xx.
 *
 * Un échec que KLASSCI déclare lui-même (`success: false`, en 2xx) n'est pas
 * une donnée. Gardé la durée d'une donnée (300 s et plus), il était resservi
 * longtemps après que le client eut reçu `Retry-After: 30` : chaque nouvel essai
 * relisait le même échec. Il n'est donc gardé que le délai annoncé au client.
 * Le garder quand même, plutôt que pas du tout, borne les appels à KLASSCI
 * pendant sa panne : un hôte qui filtre déjà sur le volume.
 *
 * Seul l'échec EXPLICITE compte. Une réponse sans clé `success` (liste nue,
 * par exemple) reste une donnée.
 *
 * Point unique de la règle : `KlassciProxyService` (lectures une à une) et
 * `KlassciBatchResponseCollector` (lots) la lisent ici. Fonction pure, sans
 * état : statique, comme `KlassciHttpClient::decorateRequest()`.
 */
final class KlassciCacheTtl
{
    /** @param  int  $ttl  la durée d'une donnée pour cet appel, en secondes */
    public static function pour(mixed $reponse, int $ttl): int
    {
        $echecDeclare = is_array($reponse) && ($reponse['success'] ?? null) === false;

        return $echecDeclare ? min($ttl, KlassciUnavailableException::retryAfterSeconds()) : $ttl;
    }
}
