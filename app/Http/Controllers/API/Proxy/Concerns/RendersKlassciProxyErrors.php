<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Proxy\Concerns;

use App\Exceptions\KlassciUnavailableException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * #270 — Traduction unifiée des échecs d'appel KLASSCI en réponse HTTP pour les
 * contrôleurs proxy.
 *
 * Les contrôleurs proxy enveloppent chaque appel dans `catch (\Exception)` et
 * renvoyaient un 500 générique. Or une {@see KlassciUnavailableException} (URL de
 * base absente/invalide #270, ou service injoignable) est une panne EXTERNE
 * temporaire : elle doit dégrader en 503 (retryable), jamais en 500 (qui suggère
 * à tort un bug serveur LMS). Toute AUTRE exception reste un 500 générique.
 *
 * Le body brut KLASSCI et le détail technique ne sont JAMAIS exposés (§1.2) :
 * on renvoie un message constant ({@see KlassciUnavailableException::CLIENT_MESSAGE}).
 */
trait RendersKlassciProxyErrors
{
    /**
     * Mappe une exception remontée d'un appel proxy KLASSCI vers la réponse HTTP
     * appropriée : 503 si indisponibilité KLASSCI, 500 sinon.
     */
    protected function proxyErrorResponse(Throwable $e): JsonResponse
    {
        if ($e instanceof \RuntimeException && $e->getCode() === 401) {
            return response()->json([
                'success' => false,
                'message' => 'Session KLASSCI expirée. Veuillez vous reconnecter.',
                'reason' => 'klassci_session_expired',
            ], 401);
        }

        if ($e instanceof KlassciUnavailableException || $e instanceof ConnectionException) {
            return response()->json([
                'success' => false,
                'message' => KlassciUnavailableException::CLIENT_MESSAGE,
            ], 503, [
                'Retry-After' => (string) KlassciUnavailableException::retryAfterSeconds(),
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Service indisponible. Veuillez réessayer.',
        ], 500);
    }
}
