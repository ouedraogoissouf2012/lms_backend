<?php

declare(strict_types=1);

namespace App\Http\Presenters;

use Illuminate\Http\JsonResponse;

/**
 * LE 429 de débit de l'API — un seul constructeur (#214, #906).
 *
 * Deux chemins le produisent : le rendu global de `ThrottleRequestsException`
 * (`bootstrap/app.php`), pour les seaux sans motif, et `Limit::response()`
 * (`RateLimitServiceProvider::refusMotive()`), pour ceux qui disent quel budget
 * est épuisé. Construire la réponse aux deux endroits laissait dériver le texte
 * comme la forme ; le front, lui, lit les deux de la même façon.
 *
 * Statique, et non injecté comme {@see AuthResponsePresenter} : il est appelé
 * depuis `bootstrap/app.php`, hors du conteneur. Même forme que
 * `KlassciUnavailableException::jsonResponse()`, pour la même raison.
 *
 * Les en-têtes reçus (`Retry-After`, `X-RateLimit-*`) sont rendus tels quels :
 * #214 les exige sur tout 429.
 *
 * @see docs/adr/2026-09-26-906-01-le-motif-des-refus.md
 */
final class TooManyRequestsPresenter
{
    /** Le texte que le front connaît : il ne change pas avec le motif. */
    public const MESSAGE = 'Trop de requêtes. Veuillez réessayer plus tard.';

    /**
     * @param  array<string, mixed>  $entetes
     */
    public static function json(array $entetes, ?string $reason = null): JsonResponse
    {
        $payload = ['success' => false, 'message' => self::MESSAGE];

        // Omis sans motif : le 429 des autres seaux garde sa forme d'avant #906.
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return response()->json($payload, 429, $entetes);
    }
}
