<?php

declare(strict_types=1);

namespace App\Services\Klassci;

/**
 * Décide si un appel KLASSCI interrompu au transport peut être rejoué, et combien
 * de fois.
 *
 * ## Le défaut corrigé (#744)
 *
 * L'hébergeur de KLASSCI n'applique pas un blocage permanent : il **avale les
 * SYN après une rafale de connexions**, puis relâche. Mesuré depuis la production
 * le 2026-09-06 : un essai échoue au bout de 11,2 s, les deux suivants
 * aboutissent en 17 ms, et douze essais espacés d'une seconde passent tous.
 *
 * Sans réessai, chacun de ces trous condamnait un appel. Pire : trois d'entre eux
 * dans la même minute ouvraient le disjoncteur, et **tous les utilisateurs
 * recevaient alors 503 pendant 30 secondes** — alors que KLASSCI répondait
 * parfaitement. C'est ce mécanisme qui transformait une gêne réseau marginale en
 * panne visible.
 *
 * ## Pourquoi les écritures ne sont JAMAIS rejouées
 *
 * On pourrait croire qu'une `ConnectionException` prouve que la requête n'est
 * jamais partie. **C'est faux, et le vérifier a changé la conception.**
 *
 * `GuzzleHttp\Handler\CurlFactory::createRejection()` range
 * `CURLE_OPERATION_TIMEOUTED` (code 28) parmi ses `$connectionErrors` et le
 * traduit en `ConnectException`, que Laravel enveloppe ensuite en
 * `ConnectionException` — exactement comme un échec de connexion. Or le code 28
 * couvre aussi un délai de **lecture** : la requête est alors bien arrivée, et
 * KLASSCI l'a peut-être traitée.
 *
 * Rejouer un `POST evaluations/{id}/notes` dans ce cas enregistrerait les notes
 * deux fois. Aucun signal ne permet de distinguer les deux situations de façon
 * fiable, donc on ne parie pas : **seul `GET` est rejoué**, et toute méthode
 * inconnue est traitée comme une écriture.
 *
 * ## Pourquoi si peu de tentatives, et si peu d'attente
 *
 * La cause est un filtre sensible au VOLUME. Marteler l'hôte aggraverait
 * précisément ce qu'on essaie de contourner. Trois tentatives au total, séparées
 * de pauses courtes et croissantes : assez pour franchir un trou de quelques
 * dizaines de millisecondes, trop peu pour ressembler à une rafale. Le cumul des
 * pauses reste sous le quart de seconde — un humain attend cette réponse.
 *
 * Verrouillé par `Tests\Unit\Services\Klassci\KlassciTransportRetryTest`.
 */
final class KlassciTransportRetry
{
    /**
     * Tentatives totales pour une lecture (1 appel + 2 réessais).
     */
    private const ATTEMPTS_FOR_READS = 3;

    /**
     * Pause de base, en microsecondes (80 ms), doublée à chaque tentative.
     */
    private const BASE_BACKOFF_MICROSECONDS = 80_000;

    /**
     * Nombre total de tentatives autorisées pour cette méthode HTTP.
     *
     * Rend `1` — soit aucun réessai — pour tout ce qui n'est pas une lecture.
     */
    public function attemptsFor(string $method): int
    {
        return strtoupper($method) === 'GET' ? self::ATTEMPTS_FOR_READS : 1;
    }

    /**
     * Pause à observer AVANT la tentative suivante, `$attempt` étant le numéro
     * (1-indexé) de la tentative qui vient d'échouer.
     */
    public function backoffMicroseconds(int $attempt): int
    {
        $palier = max(1, $attempt);

        return self::BASE_BACKOFF_MICROSECONDS * (2 ** ($palier - 1));
    }
}
