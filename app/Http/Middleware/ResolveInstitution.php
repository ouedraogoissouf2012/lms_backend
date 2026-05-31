<?php

namespace App\Http\Middleware;

use App\Models\Institution;
use App\Services\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current tenant (institution) for each incoming request.
 *
 * ## Resolution priority (CRITICAL-07)
 *
 * 1. **Bearer token present** : the institution is resolved EXCLUSIVELY from the
 *    authenticated user's `institution_id`. The `X-Institution` header is
 *    deliberately IGNORED in this case — trusting an arbitrary client header
 *    while a Sanctum token exists would let an authenticated user of school A
 *    impersonate the tenant context of school B by sending `X-Institution: B`.
 *
 * 2. **No bearer token but `X-Institution` header present** : fallback used for
 *    pre-authentication public routes (login, password reset, signup). The
 *    institution is resolved from the slug header. This is acceptable because
 *    no user identity is associated with the request yet.
 *
 * 3. **Neither token nor header** : no tenant context is set. Subsequent code
 *    relying on the tenant (e.g. `BelongsToInstitution` global scope) will
 *    behave according to its own rules.
 *
 * @see PRODUCTION_STANDARDS.md §1.2 Sécurité Absolue
 * @see .claude/agents/kfc/spec-security.md Check 5 IDOR / cross-tenant
 */
class ResolveInstitution
{
    public function __construct(private TenantManager $tenantManager)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // CRITICAL-06/07: chaque requête démarre avec un tenant non résolu.
        // Sous Octane / Swoole / FrankenPHP (et en suite PHPUnit), le container
        // Laravel et le `TenantManager` singleton survivent entre requêtes —
        // sans reset, le tenant du request précédent contamine le scope
        // `BelongsToInstitution` du request en cours et empêche la résolution
        // (User::find via tokenable retournerait null pour un user d'un autre
        // tenant). Reset = fresh state garanti à chaque entrée du middleware.
        $this->tenantManager->reset();

        // Priority 1: authenticated request — trust ONLY the JWT-bound institution.
        if ($request->bearerToken()) {
            $this->resolveFromBearerToken($request);
            return $next($request);
        }

        // Priority 2: public route — fallback on the X-Institution slug header.
        $slug = $request->header('X-Institution');

        if (!$slug) {
            // Priority 3: no token, no header — no tenant context.
            return $next($request);
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

    /**
     * Resolve the tenant from the user behind the bearer token.
     *
     * Silently leaves the tenant unset if:
     * - the token cannot be found (will trigger 401 downstream via auth:sanctum),
     * - the user has no institution_id (supradmin — sees all tenants),
     * - the institution is inactive or deleted.
     *
     * Never trusts a request header at this stage.
     */
    private function resolveFromBearerToken(Request $request): void
    {
        $token = PersonalAccessToken::findToken($request->bearerToken());

        if (!$token || !$token->tokenable || !$token->tokenable->institution_id) {
            return;
        }

        $institution = Institution::find($token->tokenable->institution_id);

        if ($institution && $institution->is_active) {
            $this->tenantManager->set($institution);
        }
    }
}
