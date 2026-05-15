<?php

namespace Tests\Feature;

use App\Http\Middleware\ResolveInstitution;
use App\Models\Institution;
use App\Models\User;
use App\Services\TenantManager;
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
}
