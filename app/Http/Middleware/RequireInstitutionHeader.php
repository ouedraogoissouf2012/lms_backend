<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Les routes de réinitialisation n'ont pas de bearer (#714).
 * Sans X-Institution le tenant est ambigu : 400, pas un succès générique.
 */
final class RequireInstitutionHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->header('X-Institution');
        if (! is_string($slug) || $slug === '') {
            return response()->json([
                'success' => false,
                'message' => 'En-tête X-Institution requis',
            ], 400);
        }

        return $next($request);
    }
}
