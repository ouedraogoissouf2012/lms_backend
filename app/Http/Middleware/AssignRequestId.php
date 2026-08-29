<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * #549 — identifiant de corrélation par requête API.
 *
 * Pose `X-Request-Id` (reprise du client s'il est sain, sinon UUID) et le
 * partage dans le contexte de logs. Jamais d'entrée libre dans les logs.
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = $this->resolveId($request);
        $request->headers->set('X-Request-Id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }

    private function resolveId(Request $request): string
    {
        $incoming = $request->headers->get('X-Request-Id');
        if (is_string($incoming) && preg_match('/^[A-Za-z0-9._-]{8,128}$/', $incoming) === 1) {
            return $incoming;
        }

        return (string) Str::uuid();
    }
}
