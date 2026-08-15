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
 *    **FAIL-SECURE (#565)** : if that institution is inactive or missing, the
 *    request is REFUSED with a 403 — never allowed to proceed with an
 *    unresolved tenant (which `BelongsToInstitution`, being fail-open, would
 *    have run UNSCOPED → cross-tenant data leak). A supradmin (no
 *    `institution_id`) still proceeds unscoped on purpose (sees all tenants).
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
            // FAIL-SECURE (#565) : un porteur rattaché à une institution
            // désactivée/introuvable est refusé ici, jamais laissé passer non
            // scopé. `null` = la requête peut continuer (tenant posé, supradmin,
            // ou token invalide traité en 401 par auth:sanctum).
            $refusal = $this->resolveFromBearerToken($request);

            if ($refusal !== null) {
                return $refusal;
            }

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
            return $this->deny(Response::HTTP_BAD_REQUEST, "Institution non trouvée ou inactive: {$slug}");
        }

        $this->tenantManager->set($institution);

        return $next($request);
    }

    /**
     * Resolve the tenant from the user behind the bearer token.
     *
     * Returns a refusal {@see Response} when the request MUST be blocked, or
     * `null` when it may proceed :
     *
     *   - token / tokenable absent          → null (auth:sanctum issues a 401),
     *   - user has no institution_id        → null (supradmin — sees all tenants),
     *   - institution active                → null (tenant set, nominal path),
     *   - institution inactive OR missing   → 403 (FAIL-SECURE, #565).
     *
     * The inactive/missing case previously returned silently, leaving the tenant
     * unresolved → `BelongsToInstitution` skipped its scope → cross-tenant leak.
     * Never trusts a request header at this stage.
     */
    private function resolveFromBearerToken(Request $request): ?Response
    {
        $token = PersonalAccessToken::findToken($request->bearerToken());

        if (!$token || !$token->tokenable) {
            return null;
        }

        $institutionId = $token->tokenable->institution_id;

        // Supradmin / platform account (no institution) — intentionally
        // cross-tenant, proceeds unscoped. MUST NOT be confused with a disabled
        // institution below.
        if (!$institutionId) {
            return null;
        }

        $institution = Institution::find($institutionId);

        if (!$institution || !$institution->is_active) {
            return $this->deny(
                Response::HTTP_FORBIDDEN,
                'Établissement désactivé. Accès suspendu, contactez votre administration.'
            );
        }

        $this->tenantManager->set($institution);

        return null;
    }

    /**
     * Réponse de refus JSON normalisée — même enveloppe pour les deux voies
     * (bearer 403 / header 400). N'expose aucun détail technique (§1.2).
     */
    private function deny(int $status, string $message): Response
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }
}
