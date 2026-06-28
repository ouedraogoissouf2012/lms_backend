<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Enseignants;

use App\Models\Institution;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Caractérisation du contrat de réponse de `LMSEnseignantsController`
 * (axe #1, groupe-07 — issue #307). Seule la réponse de succès `{success, data,
 * meta}` est migrée (les 500 portent une clé `data` sur `success:false`, hybride
 * non reproductible par le trait : non migrées, non couvertes ici).
 *
 * `meta` contient un temps d'exécution dynamique -> on asserte la STRUCTURE
 * (clés stables), pas la valeur exacte.
 *
 * @see app/Http/Controllers/API/LMS/LMSEnseignantsController.php
 */
final class LMSEnseignantsResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
    }

    public function test_success_returns_data_and_meta_envelope(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
            'klassci_token' => 'fake-token',
        ]));

        $enseignants = [['id' => 1, 'nom' => 'Diallo'], ['id' => 2, 'nom' => 'Traoré']];
        $this->mock(KlassciProxyService::class, function (MockInterface $m) use ($enseignants): void {
            $m->shouldReceive('getEnseignantsEnrichis')
                ->andReturn(['success' => true, 'data' => $enseignants, 'meta' => ['count' => 2]]);
        });

        $response = $this->getJson('/api/lms/enseignants');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data', 'meta'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame($enseignants, $body['data']);
        // meta = merge du meta KLASSCI + clés ajoutées par le controller.
        $this->assertSame(2, $body['meta']['count']);
        $this->assertSame('klassci_externe', $body['meta']['source']);
        $this->assertTrue($body['meta']['lms_cache_enabled']);
        $this->assertArrayHasKey('lms_performance', $body['meta']);
    }
}
