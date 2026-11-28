<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Concerns\InteractsWithRoles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Défense en profondeur (#511) : réserve un endpoint au gestionnaire PLATEFORME
 * (cross-tenant) via la comparaison STRICTE {@see InteractsWithRoles::isPlatformSupradmin()}.
 *
 * Deuxième couche INDÉPENDANTE, appliquée en plus de `permission:plateforme.*`, sur les
 * surfaces cross-tenant les plus sensibles (gestion des institutions : lecture/
 * écriture/rotation de tokens de TOUS les tenants). Si la logique de `EnsurePermission`
 * régressait, cette barrière stricte protégerait encore le CRUD cross-tenant.
 *
 * Réponse alignée sur EnsurePermission (401 non authentifié / 403 rôle insuffisant),
 * sans exposer de métadonnée d'autorisation au client (OWASP A06).
 *
 * @see EnsurePermission
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
            // Ce refus ne survient que si la garde primaire `permission:plateforme.*` a
            // régressé — c'est précisément l'événement que cette 2ᵉ couche existe
            // pour capter. On le rend observable côté SOC (jamais exposé au client).
            Log::warning('Accès plateforme refusé (défense en profondeur #511)', [
                'user_id' => $user->id,
                'role' => $user->role,
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
