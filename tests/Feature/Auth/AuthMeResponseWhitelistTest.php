<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

/**
 * Issue #477 — Vecteur B : GET /api/auth/me fait un appel KLASSCI LIVE
 * (AuthController::me() → KlassciProxyService::requestWithUserToken('auth/me')) et
 * remet le payload au frontend SANS passer par le stockage. La whitelist doit
 * s'appliquer aussi à ce chemin, sinon un KLASSCI compromis injecte des clés
 * directement dans la réponse frontend.
 *
 * #568 : le chemin est passé de `get()` (clé cache tenant-globale, fuite
 * d'identité) à `requestWithUserToken()` (isolation par utilisateur) — la
 * garantie de whitelist est inchangée, seule la méthode appelée évolue.
 */
final class AuthMeResponseWhitelistTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_auth_me_response_klassci_data_is_whitelisted(): void
    {
        $institution = Institution::factory()->create();
        $user = User::factory()->for($institution)->create(['klassci_token' => 'fake-token']);

        // KLASSCI compromis renvoie is_admin/permissions en LIVE sur auth/me.
        // #568 : /auth/me passe par requestWithUserToken (isolation par token perso).
        $mock = Mockery::mock(KlassciProxyService::class);
        $mock->shouldReceive('requestWithUserToken')
            ->with('fake-token', 'auth/me', 'GET')
            ->andReturn(['data' => ['user' => [
                'id' => 123,
                'nom' => 'Dupont',
                'role' => 'enseignant',
                'is_admin' => true,
                'permissions' => ['*'],
                'scopes' => ['god-mode'],
            ]]]);
        $this->app->instance(KlassciProxyService::class, $mock);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $klassciData = $response->json('data.klassci_data');

        // Clés injectées absentes de la réponse frontend.
        self::assertArrayNotHasKey('is_admin', $klassciData);
        self::assertArrayNotHasKey('permissions', $klassciData);
        self::assertArrayNotHasKey('scopes', $klassciData);
        // Clés display conservées.
        self::assertSame('Dupont', $klassciData['nom'] ?? null);
        self::assertSame(123, $klassciData['id'] ?? null);
    }
}
