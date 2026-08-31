<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy\Concerns;

use App\Exceptions\KlassciUnavailableException;
use App\Support\Klassci\KlassciClientErrorCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * #270 — Traduction unifiée des échecs d'appel KLASSCI en réponse HTTP pour les
 * contrôleurs proxy.
 *
 * Une {@see KlassciUnavailableException} (URL de base absente/invalide, service
 * injoignable) est une panne EXTERNE temporaire : elle dégrade en 503 retryable,
 * jamais en 500 qui suggérerait à tort un bug du serveur LMS.
 *
 * ## Le 4xx amont est désormais RELAYÉ
 *
 * Auparavant, seul le 401 était traité : tout le reste — y compris un 403
 * d'AUTORISATION — s'écrasait en 500 « Service indisponible ». Un refus de droits
 * devenait alors indiscernable d'une panne, pour l'utilisateur (message faux) comme
 * pour la supervision (des 5xx sans le moindre incident). Constaté en local : les 17
 * appels `/proxy/classes/{id}/etudiants` renvoyaient 500 quand KLASSCI répondait 403.
 *
 * Le trait frère {@see \App\Http\Controllers\API\Concerns\RendersKlassciBackedErrors}
 * relayait déjà correctement ; ce trait-ci était le seul à diverger. La classification
 * qu'ils partagent vit maintenant dans {@see KlassciClientErrorCatalog}.
 *
 * ## Ordre des branches — ne pas réordonner
 *
 * `KlassciUnavailableException` étend elle aussi `RuntimeException` : testée APRÈS le
 * relais de statut, elle en ressortirait avec son propre code et sans `Retry-After`.
 * Elle passe donc en premier. La borne haute 499 rend aujourd'hui les deux branches
 * disjointes, mais l'ordre reste la garde qui survit à une évolution de cette borne.
 *
 * Le body brut KLASSCI et le détail technique ne sont JAMAIS exposés (§1.2) : les
 * messages viennent d'un catalogue en dur, jamais de `$e->getMessage()` (qui vaut
 * « Erreur API KLASSCI: 403 »).
 */
trait RendersKlassciProxyErrors
{
    /**
     * Mappe une exception remontée d'un appel proxy KLASSCI vers la réponse HTTP
     * appropriée : 503 si indisponibilité, 401 si session expirée, relais du 4xx
     * amont sinon, et 500 pour tout ce qui n'est pas imputable à KLASSCI.
     */
    protected function proxyErrorResponse(Throwable $e): JsonResponse
    {
        // (1) Panne externe temporaire : réponse canonique, avec `Retry-After`.
        if ($e instanceof KlassciUnavailableException || $e instanceof ConnectionException) {
            return KlassciUnavailableException::jsonResponse();
        }

        if ($e instanceof \RuntimeException) {
            $status = $e->getCode();

            // (2) Session KLASSCI expirée : `reason` est un contrat que les clients
            // consomment pour ré-authentifier SANS perdre la session LMS.
            if ($status === 401) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session KLASSCI expirée. Veuillez vous reconnecter.',
                    'reason' => 'klassci_session_expired',
                ], 401);
            }

            // (3) Autre erreur client amont (403, 404, 409, 422, 429…) : relayée avec
            // son vrai statut. Volontairement une PLAGE et non une liste choisie —
            // le client HTTP ne lève cette exception que pour 400–499, et une liste
            // laisserait les statuts non prévus retomber dans le 500 qu'on corrige.
            if (KlassciClientErrorCatalog::isRelayableClientStatus($status)) {
                return response()->json([
                    'success' => false,
                    'message' => KlassciClientErrorCatalog::messageFor($status),
                ], $status);
            }
        }

        // (4) Ni panne KLASSCI, ni erreur client amont : défaillance interne.
        return response()->json([
            'success' => false,
            'message' => 'Service indisponible. Veuillez réessayer.',
        ], 500);
    }
}
