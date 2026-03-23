<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveInstitution
{
    public function __construct(private TenantManager $tenantManager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Institution');

        // Pas de header X-Institution mais token Bearer présent :
        // résoudre l'institution depuis l'utilisateur authentifié
        if (!$slug && $request->bearerToken()) {
            $token = \Laravel\Sanctum\PersonalAccessToken::findToken($request->bearerToken());

            if ($token && $token->tokenable && $token->tokenable->institution_id) {
                $institution = Institution::find($token->tokenable->institution_id);

                if ($institution && $institution->is_active) {
                    $this->tenantManager->set($institution);
                }
            }
            // Si institution_id est null (supradmin) → pas de filtre, il voit tout
            return $next($request);
        }

        // Pas de token Bearer → fallback sur 'presentation' pour les routes publiques
        if (!$slug) {
            $slug = 'presentation';
        }

        $institution = Institution::where('slug', $slug)
            ->where('is_active', true)
            ->first();

        if (!$institution) {
            return response()->json([
                'success' => false,
                'message' => "Institution non trouvée ou inactive: {$slug}",
            ], 400);
        }

        $this->tenantManager->set($institution);

        return $next($request);
    }
}
