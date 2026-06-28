<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Matieres;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Caractérisation du contrat de réponse de `LMSMatieresAdminController`
 * (axe #1, groupe-07 — issue #306). Route /api/admin/matieres (role admin).
 *
 * Le 403 « defense in depth » du controller est inatteignable via HTTP (le
 * middleware `role:admin,coordinateur` filtre avant) : non couvert. Le 500
 * (clé racine `error`) n'est pas migré : non couvert.
 *
 * @see app/Http/Controllers/API/LMS/LMSMatieresAdminController.php
 */
final class LMSMatieresAdminResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    private function actingAsAdmin(?string $token = 'fake-token'): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'admin',
            'klassci_token' => $token,
        ]));
    }

    public function test_without_klassci_token_returns_401_envelope(): void
    {
        $this->actingAsAdmin(token: null);

        $this->getJson('/api/admin/matieres')
            ->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ]);
    }

    public function test_no_matiere_returns_200_empty_with_message(): void
    {
        $this->actingAsAdmin();
        $this->mock(KlassciProxyService::class, function (MockInterface $m): void {
            $m->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'matieres', 'GET')
                ->andReturn(['data' => []]);
        });

        $this->getJson('/api/admin/matieres')
            ->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'data' => [
                    'matieres' => [],
                    'statistiques' => ['total' => 0, 'total_heures' => 0, 'total_seances' => 0],
                ],
                'message' => 'Aucune matière trouvée',
            ]);
    }

    public function test_with_matieres_returns_200_success_envelope(): void
    {
        $this->actingAsAdmin();
        $this->mock(KlassciProxyService::class, function (MockInterface $m): void {
            $m->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'matieres', 'GET')
                ->andReturn(['data' => [['id' => 1, 'nom' => 'Maths', 'code' => 'MA', 'heures_total' => 3]]]);
            $m->shouldReceive('requestWithUserToken')
                ->with('fake-token', 'matieres/1', 'GET')
                ->andReturn(['data' => ['combinaisons' => []]]);
        });

        $response = $this->getJson('/api/admin/matieres');

        $response->assertStatus(200);
        $body = $response->json();
        // Mêmes clés exactement (l'ordre des clés JSON n'est pas un contrat client).
        $this->assertEqualsCanonicalizing(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('1 matière(s) récupérée(s)', $body['message']);
        $this->assertCount(1, $body['data']['matieres']);
        $this->assertSame(1, $body['data']['statistiques']['total']);
    }
}
