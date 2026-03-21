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

        // Pas de header X-Institution
        if (!$slug) {
            // Si un token Bearer est présent, laisser passer sans résoudre d'institution
            // (cas du supradmin qui n'appartient à aucune école)
            if ($request->bearerToken()) {
                return $next($request);
            }
            // Sinon, fallback sur 'presentation' pour les routes publiques
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
