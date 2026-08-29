<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Issue #504 (bout en bout) — `POST /api/auth/login` (flux KLASSCI) ne doit plus
 * exposer `is_admin`/`permissions`/`admin_data` BRUTS dans `data.user`. Complète
 * la voie login, en pendant de {@see AuthMeResponseWhitelistTest} (voie /auth/me)
 * et des tests de stockage #477 — les 3 voies live sont désormais cohérentes.
 *
 * @see app/Http/Controllers/API/AuthController.php::attemptKlassciLogin
 * @see app/Http/Presenters/AuthResponsePresenter.php::successfulKlassci
 */
final class LoginResponseWhitelistTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_login_response_excludes_raw_klassci_privilege_fields(): void
    {
        Institution::factory()->create(['slug' => 'demo']);

        // La discovery multi-tenant résout le tenant "demo".
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')
                ->andReturn([['code' => 'demo', 'api_base_url' => 'https://demo.klassci.test']]);
        });

        // KLASSCI (potentiellement compromis) renvoie is_admin/permissions/admin_data
        // au login — y compris is_admin imbriqué dans admin_data.
        $this->mock(KlassciAuthClient::class, function ($mock): void {
            $mock->shouldReceive('attemptLogin')->andReturn([
                'data' => [
                    'user' => [
                        'id'                => 42,
                        'klassci_id'        => 'k-42',
                        'nom'               => 'Dupont',
                        'name'              => 'Dupont',
                        'email'             => 'dupont@demo.test',
                        'role'              => 'enseignant',
                        'is_admin'          => true,
                        'permissions'       => ['*'],
                        'admin_data'        => ['etablissement' => 'Lycee X', 'is_admin' => true],
                        'avatar'            => 'avatar.png',
                        'role_display_name' => 'Enseignant',
                    ],
                    'token' => 'klassci-token',
                ],
                'meta' => ['annee_universitaire_courante' => '2025-2026'],
            ]);
        });

        $response = $this->postJson('/api/auth/login', [
            'username' => 'dupont',
            'password' => 'motdepasse123',
        ]);

        $response->assertOk();
        $user = $response->json('data.user');

        // Clés dangereuses absentes de la réponse de login.
        self::assertArrayNotHasKey('is_admin', $user);
        self::assertArrayNotHasKey('permissions', $user);
        self::assertArrayNotHasKey('admin_data', $user);

        // Champs légitimes conservés + institution_name dérivé d'admin_data en meta.
        self::assertSame('enseignant', $user['role']);
        self::assertSame('avatar.png', $user['avatar']);
        self::assertSame('Lycee X', $response->json('meta.institution_name'));
    }
}
