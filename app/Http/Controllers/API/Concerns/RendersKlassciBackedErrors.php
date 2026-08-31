<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Concerns;

use App\Exceptions\KlassciUnavailableException;
use App\Support\Klassci\KlassciClientErrorCatalog;
use App\Exceptions\MissingKlassciTokenException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Traduit en réponse HTTP les échecs d'un endpoint LMS adossé à KLASSCI.
 *
 * Sépare trois causes que les contrôleurs confondaient sous un unique
 * `catch (RuntimeException)` répondant invariablement 401 :
 *
 *   1. Jeton réellement absent          → 401, l'utilisateur doit se reconnecter.
 *   2. KLASSCI indisponible (5xx, circuit ouvert) → 503 + `Retry-After`.
 *   3. KLASSCI répond une erreur 4xx    → on relaie SON statut.
 *
 * Le cas qui a motivé la séparation : un compte sans profil enseignant côté
 * KLASSCI. KLASSCI répond 404 « Profil enseignant introuvable » ; l'ancien code
 * renvoyait 401 « reconnectez-vous ». Le frontend y lisait une session expirée et
 * déconnectait un utilisateur parfaitement authentifié, dont le seul tort était
 * de ne pas être enseignant. Le vrai motif ne figurait que dans les logs.
 *
 * ## Pourquoi ce trait n'est PAS {@see \App\Http\Controllers\API\Proxy\Concerns\RendersKlassciProxyErrors}
 *
 * Les deux classent les mêmes exceptions, mais n'émettent pas la même enveloppe :
 * le proxy renvoie une clé `reason` que ses clients consomment, et écrit sa
 * réponse à la main ; les contrôleurs LMS passent par `errorResponse()`
 * ({@see \App\Http\Controllers\Concerns\RespondsWithJson}). Les fusionner
 * imposerait de changer l'un des deux contrats côté client.
 *
 * DETTE TRACÉE : la classification (quel statut signifie quoi) est donc écrite
 * deux fois. Le 503 est déjà factorisé dans
 * {@see KlassciUnavailableException::jsonResponse()} ; le reste ne l'est pas.
 * Unifier demande un golden master des payloads proxy, hors périmètre ici.
 *
 * @see \App\Services\Klassci\KlassciHttpClient  lève RuntimeException($status) sur 4xx
 */
trait RendersKlassciBackedErrors
{
    /**
     * Rend TOUJOURS une réponse : l'appelant n'a aucun repli à recopier.
     *
     * Un repli laissé à chaque site d'appel, c'est exactement ce qui a produit le
     * défaut corrigé ici — huit `catch` écrits séparément ayant fini par
     * confondre deux causes.
     */
    protected function renderKlassciFailure(RuntimeException $e): JsonResponse
    {
        // Panne externe temporaire : réponse canonique, avec `Retry-After`.
        // Doit précéder le relais de statut ci-dessous, car cette exception est
        // elle aussi une RuntimeException et porte un code 5xx qui serait sinon
        // relayé tel quel, sans en-tête et avec un message erroné.
        if ($e instanceof KlassciUnavailableException) {
            return KlassciUnavailableException::jsonResponse();
        }

        if ($e instanceof MissingKlassciTokenException) {
            return $this->errorResponse(MissingKlassciTokenException::CLIENT_MESSAGE, 401);
        }

        $status = $e->getCode();

        // Hors 4xx : l'exception n'est pas imputable à KLASSCI (les 5xx KLASSCI
        // sont déjà convertis en KlassciUnavailableException par le client HTTP).
        if (! KlassciClientErrorCatalog::isRelayableClientStatus($status)) {
            return $this->errorResponse('Une erreur est survenue.', 500);
        }

        return $this->errorResponse(KlassciClientErrorCatalog::messageFor($status), $status);
    }
}
