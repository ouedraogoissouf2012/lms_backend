<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsureRole;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Issue #511 — L'accès au rôle PLATEFORME `supradmin` (cross-tenant) via
 * `EnsureRole` doit être STRICT, aligné sur {@see \App\Models\Concerns\InteractsWithRoles::isPlatformSupradmin()}
 * (comparaison exacte `role === 'supradmin'`).
 *
 * Avant : `strtolower(trim($user->role)) === 'supradmin'` laissait une variante
 * de casse (`'Supradmin'`, `'SUPRADMIN'`) franchir le bypass plateforme, alors
 * que les gardes de LECTURE cross-tenant (FileQueryService, NotificationQueryService)
 * la rejettent. Divergence exploitable (accès aux routes de gestion d'institutions).
 *
 * @see app/Http/Middleware/EnsureRole.php
 */
final class EnsureRoleSupradminStrictTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create(['slug' => 'ensure-role-511']);
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => $role,
            'email'          => 'u'.uniqid().'@511.test',
        ]);
    }

    private function runMiddleware(User $user, string ...$requiredRoles): Response
    {
        $request = Request::create('/api/dummy', 'GET');
        $request->setUserResolver(fn () => $user);

        return (new EnsureRole())->handle($request, fn () => response('ok'), ...$requiredRoles);
    }

    public function test_strict_supradmin_passes_supradmin_route(): void
    {
        $response = $this->runMiddleware($this->userWithRole('supradmin'), 'supradmin');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * 🔑 Le cœur du #511 : une variante de casse ne doit PAS franchir une route
     * `role:supradmin` (elle n'est pas isPlatformSupradmin).
     */
    public function test_case_variant_supradmin_is_denied_on_supradmin_route(): void
    {
        foreach (['Supradmin', 'SUPRADMIN', 'SuprAdmin'] as $variant) {
            $response = $this->runMiddleware($this->userWithRole($variant), 'supradmin');

            self::assertSame(403, $response->getStatusCode(), "[$variant] ne doit pas passer une route supradmin");
        }
    }

    /**
     * L'admin d'INSTITUTION `superAdmin` (intra-tenant) ne doit PAS accéder aux
     * routes réservées au gestionnaire plateforme.
     */
    public function test_institution_superadmin_is_denied_on_supradmin_route(): void
    {
        $response = $this->runMiddleware($this->userWithRole('superAdmin'), 'supradmin');

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * Non-régression : `superAdmin` (admin institution) garde son bypass sur les
     * routes NON réservées au plateforme.
     */
    public function test_institution_superadmin_still_bypasses_non_supradmin_routes(): void
    {
        $response = $this->runMiddleware($this->userWithRole('superAdmin'), 'coordinateur', 'admin');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Non-régression : le platform supradmin garde son bypass total sur les
     * routes non-supradmin.
     */
    public function test_platform_supradmin_bypasses_any_route(): void
    {
        $response = $this->runMiddleware($this->userWithRole('supradmin'), 'enseignant');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Non-régression : route MIXTE `supradmin,admin` — un admin passe (via 'admin'),
     * un platform supradmin passe, mais une variante de casse 'Supradmin' est refusée.
     */
    public function test_mixed_route_preserves_admin_and_blocks_case_variant(): void
    {
        self::assertSame(200, $this->runMiddleware($this->userWithRole('admin'), 'supradmin', 'admin')->getStatusCode());
        self::assertSame(200, $this->runMiddleware($this->userWithRole('supradmin'), 'supradmin', 'admin')->getStatusCode());
        self::assertSame(403, $this->runMiddleware($this->userWithRole('Supradmin'), 'supradmin', 'admin')->getStatusCode());
    }
}
