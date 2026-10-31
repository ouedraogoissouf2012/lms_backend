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
 * Un échec est soit EXPLICITE (`success: false`), soit dit par le lecteur de
 * la réponse : lui seul sait quel bloc il attend (le roster d'une classe, par
 * exemple), et il le dit par `$exploitable`. Sans cette règle, une réponse sans
 * clé `success` (liste nue, par exemple) reste une donnée.
 *
 * Point unique de la règle : `KlassciProxyService` (lectures une à une) et
 * `KlassciBatchResponseCollector` (lots) la lisent ici. Fonction pure, sans
 * état : statique, comme `KlassciHttpClient::decorateRequest()`.
 */
final class KlassciCacheTtl
{
    /**
     * @param  int  $ttl  la durée d'une donnée pour cet appel, en secondes
     * @param  (\Closure(mixed): bool)|null  $exploitable  la règle du lecteur, s'il en a une
     */
    public static function pour(mixed $reponse, int $ttl, ?\Closure $exploitable = null): int
    {
        $echecDeclare = is_array($reponse) && ($reponse['success'] ?? null) === false;
        $inexploitable = $exploitable !== null && ! $exploitable($reponse);

        return $echecDeclare || $inexploitable ? min($ttl, KlassciUnavailableException::retryAfterSeconds()) : $ttl;
    }
}
