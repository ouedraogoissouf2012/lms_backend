<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Http\Middleware\EnsurePlatformSupradmin;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Issue #511 — middleware de défense en profondeur : réserve un endpoint au
 * gestionnaire PLATEFORME via la comparaison STRICTE isPlatformSupradmin().
 *
 * @see app/Http/Middleware/EnsurePlatformSupradmin.php
 */
final class EnsurePlatformSupradminTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create(['slug' => 'platform-mw-511']);
    }

    private function runMiddleware(?User $user): Response
    {
        $request = Request::create('/api/dummy', 'GET');
        $request->setUserResolver(fn () => $user);

        return (new EnsurePlatformSupradmin())->handle($request, fn () => response('ok'));
    }

    private function userWithRole(string $role): User
    {
        return User::factory()->create([
            'institution_id' => $this->institution->id,
            'role'           => $role,
            'email'          => 'u'.uniqid().'@mw511.test',
        ]);
    }

    public function test_platform_supradmin_passes(): void
    {
        $response = $this->runMiddleware($this->userWithRole('supradmin'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $response->getContent());
    }

    public function test_unauthenticated_gets_401(): void
    {
        self::assertSame(401, $this->runMiddleware(null)->getStatusCode());
    }

    /**
     * Tout ce qui n'est PAS strictement `supradmin` est refusé — y compris
     * l'admin d'institution `superAdmin` et les variantes de casse.
     */
    public function test_non_platform_roles_get_403(): void
    {
        foreach (['superAdmin', 'admin', 'coordinateur', 'enseignant', 'etudiant', 'Supradmin', 'SUPRADMIN'] as $role) {
            self::assertSame(403, $this->runMiddleware($this->userWithRole($role))->getStatusCode(), "[$role] doit être refusé");
        }
    }

    public function test_403_body_leaks_no_authorization_metadata(): void
    {
        $body = json_decode($this->runMiddleware($this->userWithRole('admin'))->getContent(), true);

        self::assertFalse($body['success']);
        self::assertArrayNotHasKey('required_roles', $body);
        self::assertArrayNotHasKey('your_role', $body);
    }
}
