<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Institution;
use App\Models\User;
use App\Services\Klassci\Auth\KlassciAuthClient;
use App\Services\Klassci\Auth\KlassciTenantDiscovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #1409 — le compte connecté dit son identité d'ENSEIGNANT KLASSCI (`klassci_enseignant_id`),
 * à côté de son numéro de COMPTE KLASSCI (`klassci_id`) et de son id local.
 *
 * Les listes d'enseignants d'une école KLASSCI (`/proxy/enseignants`, `/lms/enseignants`) et
 * l'enseignant d'une évaluation (#873) sont dans l'espace enseignant : sans ce champ, le front ne
 * reconnaît « moi » dans ces listes que par un id d'un autre espace (frontend_lms#626).
 *
 * Les deux numéros KLASSCI sont DIFFÉRENTS ici (compte 9001, enseignant 42) : un champ qui
 * rendrait l'un pour l'autre serait vu. Deux volets : école KLASSCI ; école autonome (null).
 */
final class LeCompteConnecteDitSonIdentiteDEnseignantTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MOT_DE_PASSE = 'mot-de-passe-de-test';

    public function test_la_connexion_klassci_rend_le_numero_de_compte_et_l_identite_d_enseignant(): void
    {
        Institution::factory()->create(['slug' => 'demo']);
        $this->mock(KlassciTenantDiscovery::class, function ($mock): void {
            $mock->shouldReceive('findMatchingTenants')
                ->andReturn([['code' => 'demo', 'api_base_url' => 'https://demo.klassci.test']]);
        });
        $this->mock(KlassciAuthClient::class, function ($mock): void {
            $mock->shouldReceive('attemptLogin')->andReturn([
                'data' => [
                    'user' => [
                        'id' => 9001, 'enseignant_id' => 42, 'nom' => 'Dupont', 'name' => 'Dupont',
                        'email' => 'dupont@demo.test', 'role' => 'enseignant',
                    ],
                    'token' => 'klassci-token',
                ],
                'meta' => [],
            ]);
        });

        $this->postJson('/api/auth/login', ['username' => 'dupont', 'password' => self::MOT_DE_PASSE])
            ->assertOk()
            ->assertJsonPath('data.user.klassci_id', 9001)
            ->assertJsonPath('data.user.klassci_enseignant_id', 42);
    }

    public function test_dans_une_ecole_autonome_la_connexion_locale_rend_une_identite_nulle(): void
    {
        $ecole = Institution::factory()->standalone()->create();
        $compte = User::factory()->for($ecole)->teacher()->create([
            'password' => Hash::make(self::MOT_DE_PASSE),
            'klassci_id' => null,
            'klassci_enseignant_id' => null,
        ]);

        $reponse = $this->postJson('/api/auth/login', ['username' => $compte->email, 'password' => self::MOT_DE_PASSE])
            ->assertOk()
            ->assertJsonPath('data.user.id', $compte->id);

        $user = (array) $reponse->json('data.user');
        self::assertArrayHasKey('klassci_enseignant_id', $user, 'Le champ est absent : le front ne peut pas distinguer « aucun » de « non dit ».');
        self::assertNull($user['klassci_enseignant_id']);
    }

    public function test_auth_me_rend_le_meme_champ(): void
    {
        $ecole = Institution::factory()->create();
        $compte = User::factory()->for($ecole)->teacher()->create([
            'klassci_id' => 9001,
            'klassci_enseignant_id' => 42,
            'klassci_token' => null,
        ]);

        $this->asTenant($compte)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $compte->id)
            ->assertJsonPath('data.klassci_id', 9001)
            ->assertJsonPath('data.klassci_enseignant_id', 42);
    }
}
