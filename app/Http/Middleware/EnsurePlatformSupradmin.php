<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Défense en profondeur (#511) : réserve un endpoint au gestionnaire PLATEFORME
 * (cross-tenant) via la comparaison STRICTE {@see \App\Models\Concerns\InteractsWithRoles::isPlatformSupradmin()}.
 *
 * Deuxième couche INDÉPENDANTE, appliquée en plus de `role:supradmin`, sur les
 * surfaces cross-tenant les plus sensibles (gestion des institutions : lecture/
 * écriture/rotation de tokens de TOUS les tenants). Si la logique de `EnsureRole`
 * régressait, cette barrière stricte protégerait encore le CRUD cross-tenant.
 *
 * Réponse alignée sur EnsureRole (401 non authentifié / 403 rôle insuffisant),
 * sans exposer de métadonnée d'autorisation au client (OWASP A06).
 *
 * @see app/Http/Middleware/EnsureRole.php
 * @see app/Http/Controllers/API/InstitutionController.php
 */
final class EnsurePlatformSupradmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        if (! $user->isPlatformSupradmin()) {
            // Ce refus ne survient que si la garde primaire `role:supradmin` a
            // régressé — c'est précisément l'événement que cette 2ᵉ couche existe
            // pour capter. On le rend observable côté SOC (jamais exposé au client).
            Log::warning('Accès plateforme refusé (défense en profondeur #511)', [
                'user_id'  => $user->id,
                'role'     => $user->role,
                'endpoint' => $request->path(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Accès refusé - Permissions insuffisantes',
            ], 403);
        }

        return $next($request);
    }
}
