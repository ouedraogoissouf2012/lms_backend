<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveInstitution;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * CRITICAL-07: ResolveInstitution middleware must trust the JWT, not the header,
 *              as soon as a bearer token is present.
 *
 * @see app/Http/Middleware/ResolveInstitution.php
 */
class ResolveInstitutionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institutionA;
    private Institution $institutionB;
    private User $userOfA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institutionA = Institution::factory()->create(['slug' => 'ecole-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'ecole-b']);

        $this->userOfA = User::factory()->create([
            'institution_id' => $this->institutionA->id,
            'email'          => 'user-a@example.com',
            'password'       => Hash::make('secret-password'),
            'role'           => 'etudiant',
        ]);
    }

    /**
     * Vulnerability the fix protects against: user of A tries to switch tenant
     * to B by sending `X-Institution: ecole-b` together with their bearer token.
     * The middleware must IGNORE the header and resolve A from the token.
     */
    public function test_bearer_token_wins_over_xinstitution_header(): void
    {
        $plainToken = $this->userOfA->createToken('test')->plainTextToken;

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);
        $request->headers->set('X-Institution', $this->institutionB->slug);

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $middleware = new ResolveInstitution($tenantManager);
        $middleware->handle($request, fn () => response('ok'));

        $resolved = $tenantManager->get();
        $this->assertNotNull($resolved, 'Tenant must be resolved when a valid bearer token is present');
        $this->assertSame(
            $this->institutionA->id,
            $resolved->id,
            'Tenant must come from the bearer token user, NOT from the X-Institution header'
        );
    }

    /**
     * Without a bearer token, the X-Institution header is the only source of
     * tenant context (legitimate fallback for public pre-auth routes).
     */
    public function test_xinstitution_header_used_when_no_bearer_token(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('X-Institution', $this->institutionA->slug);

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $middleware = new ResolveInstitution($tenantManager);
        $middleware->handle($request, fn () => response('ok'));

        $resolved = $tenantManager->get();
        $this->assertNotNull($resolved);
        $this->assertSame($this->institutionA->id, $resolved->id);
    }

    /**
     * Unknown slug must produce a 400 — never silently fall through.
     */
    public function test_unknown_xinstitution_slug_returns_400(): void
    {
        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('X-Institution', 'school-that-does-not-exist');

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $middleware = new ResolveInstitution($tenantManager);
        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * Inactive institution must NOT be resolved as tenant via header.
     */
    public function test_inactive_institution_not_resolved_via_header(): void
    {
        $inactive = Institution::factory()->create([
            'slug'      => 'inactive-school',
            'is_active' => false,
        ]);

        $request = Request::create('/api/auth/login', 'POST');
        $request->headers->set('X-Institution', $inactive->slug);

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $middleware = new ResolveInstitution($tenantManager);
        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * No token + no header → no tenant resolved, request passes through.
     */
    public function test_no_token_no_header_no_tenant(): void
    {
        $request = Request::create('/api/ping', 'GET');

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $middleware = new ResolveInstitution($tenantManager);
        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($tenantManager->get());
    }

    /**
     * Supradmin user (institution_id NULL) → no tenant resolved, but request
     * proceeds normally. Supradmin sees all tenants.
     */
    public function test_supradmin_user_does_not_set_tenant(): void
    {
        $supradmin = User::factory()->create([
            'institution_id' => null,
            'email'          => 'supradmin@example.com',
            'role'           => 'supradmin',
        ]);

        $plainToken = $supradmin->createToken('test')->plainTextToken;

        $request = Request::create('/api/admin/institutions', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $middleware = new ResolveInstitution($tenantManager);
        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNull($tenantManager->get());
    }

    /**
     * #565 (P0) — FAIL-SECURE : un porteur dont l'institution est désactivée
     * doit être REFUSÉ (403), jamais laissé passer avec un tenant non résolu
     * (ce qui laissait `BelongsToInstitution` tourner non scopé → fuite
     * cross-tenant). Le `$next` ne doit même pas être invoqué.
     */
    public function test_disabled_institution_via_bearer_returns_403_and_no_tenant(): void
    {
        $this->institutionA->is_active = false;
        $this->institutionA->save();

        $plainToken = $this->userOfA->createToken('test')->plainTextToken;

        $request = Request::create('/api/lessons', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $nextCalled = false;
        $middleware = new ResolveInstitution($tenantManager);
        $response = $middleware->handle($request, function () use (&$nextCalled) {
            $nextCalled = true;

            return response('ok');
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($nextCalled, 'La requête ne doit jamais atteindre le contrôleur');
        $this->assertNull($tenantManager->get(), 'Aucun tenant ne doit être posé');
    }

    /**
     * #565 — un porteur rattaché à une institution INTROUVABLE (id sans ligne,
     * ex. suppression physique) est également refusé (403) : jamais non scopé.
     */
    public function test_missing_institution_via_bearer_returns_403(): void
    {
        // #583 : la FK institution_id rend un id inexistant impossible en base.
        // On simule « institution non résoluble » via un soft-delete (#567) : la
        // ligne existe (FK satisfaite), masquée par Institution::find() → 403 attendu.
        $ghost = Institution::factory()->create();
        $orphan = User::factory()->create([
            'institution_id' => $ghost->id,
            'email'          => 'orphan@example.com',
            'role'           => 'etudiant',
        ]);
        $plainToken = $orphan->createToken('test')->plainTextToken;
        $ghost->delete();

        $request = Request::create('/api/lessons', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . $plainToken);

        $this->app->forgetInstance(TenantManager::class);
        $tenantManager = app(TenantManager::class);

        $middleware = new ResolveInstitution($tenantManager);
        $response = $middleware->handle($request, fn () => response('ok'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($tenantManager->get());
    }
}
