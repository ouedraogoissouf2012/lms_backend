<?php

declare(strict_types=1);

namespace Tests\Feature\LMS\Seances;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `LMSSeancesHistoryController`
 * (axe #1, groupe-06 — test-first, controller non couvert jusqu'ici).
 *
 * Le succès expose une clé sœur `pagination` à la racine (à côté de `data`).
 * Le trait `RespondsWithJson`, DRY-only, n'a aucun paramètre `pagination` et son
 * paramètre `meta` renommerait la clé → la sortie ne serait PAS identique. Cette
 * réponse n'est donc PAS migrée ; ce test fige sa forme exacte pour documenter
 * et verrouiller ce contrat (et détecter toute régression future).
 *
 * L'historique interroge la BDD locale uniquement (séances `visio_started_at`
 * non nulles), sans appel KLASSCI : le succès est donc testable de bout en bout.
 *
 * @see app/Http/Controllers/API/LMS/LMSSeancesHistoryController.php
 */
final class LMSSeancesHistoryResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
    }

    public function test_history_success_exposes_data_and_pagination_siblings(): void
    {
        $coordinateur = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'coordinateur',
            'klassci_id' => 555,
            'klassci_token' => 'fake-token',
        ]);
        Sanctum::actingAs($coordinateur);

        // Une séance avec visio démarrée → éligible à l'historique.
        Seance::factory()->visioActive()->create([
            'institution_id' => $this->institution->id,
        ]);

        $response = $this->getJson('/api/lms/seances/history');

        $response->assertStatus(200);
        $body = $response->json();

        // L'enveloppe expose `data` ET `pagination` à la racine (clés sœurs).
        $this->assertSame(['success', 'data', 'pagination'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertCount(1, $body['data']);
        $this->assertSame(
            ['current_page', 'per_page', 'total', 'last_page'],
            array_keys($body['pagination'])
        );
        $this->assertSame(1, $body['pagination']['total']);
    }
}
