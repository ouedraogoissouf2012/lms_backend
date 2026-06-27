<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `InstitutionController`
 * (axe #1 — controller MIXTE, test-first AVANT migration vers `RespondsWithJson`).
 *
 * Fige la forme EXACTE des réponses migrables (6 succès + helpers d'erreur
 * `notFound`/`validationError`) afin que la migration ne change RIEN côté client.
 *
 * NON couvert (et NON migré) : `testConnection` renvoie le payload BRUT du
 * service (clé racine, sans enveloppe contrôlée) et un 502 portant une clé
 * `data` — incompatibles avec `successResponse`/`errorResponse`, laissés inline.
 *
 * @see app/Http/Controllers/API/InstitutionController.php
 */
final class InstitutionResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $supradmin;
    private Institution $instA;
    private Institution $instB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supradmin = User::factory()->create([
            'institution_id' => null,
            'role' => 'supradmin',
        ]);
        $this->instA = Institution::factory()->create(['is_active' => true]);
        $this->instB = Institution::factory()->create(['is_active' => true]);

        Sanctum::actingAs($this->supradmin);
    }

    // ───────────────────────── index ─────────────────────────

    public function test_index_returns_success_with_data_only(): void
    {
        $response = $this->getJson('/api/admin/institutions');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame(['institutions', 'overview'], array_keys($body['data']));
    }

    // ───────────────────────── show ─────────────────────────

    public function test_show_returns_success_with_data_only(): void
    {
        $response = $this->getJson("/api/admin/institutions/{$this->instA->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame(['institution', 'stats'], array_keys($body['data']));
    }

    public function test_show_missing_returns_404_error_envelope(): void
    {
        $response = $this->getJson('/api/admin/institutions/999999');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Institution non trouvée']);
    }

    // ───────────────────────── store ─────────────────────────

    public function test_store_returns_201_with_message_and_data(): void
    {
        $response = $this->postJson('/api/admin/institutions', [
            'slug' => 'ecole-test',
            'name' => 'École Test',
            'klassci_api_url' => 'https://klassci.example.com',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Institution créée avec succès', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function test_store_invalid_returns_422_validation_error_envelope(): void
    {
        $response = $this->postJson('/api/admin/institutions', []);

        $response->assertStatus(422);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'errors'], array_keys($body));
        $this->assertFalse($body['success']);
        $this->assertSame('Erreur de validation', $body['message']);
        $this->assertIsArray($body['errors']);
    }

    // ───────────────────────── update ─────────────────────────

    public function test_update_returns_success_with_message_and_data(): void
    {
        $response = $this->putJson("/api/admin/institutions/{$this->instA->id}", [
            'name' => 'Nom mis à jour',
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Institution mise à jour avec succès', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── toggle ─────────────────────────

    public function test_toggle_returns_success_with_dynamic_message_and_data(): void
    {
        // Deux institutions actives : désactiver instA est autorisé (le garde-fou
        // « dernière active » ne se déclenche pas).
        $response = $this->patchJson("/api/admin/institutions/{$this->instA->id}/toggle");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Institution désactivée', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── destroy ─────────────────────────

    public function test_destroy_returns_success_with_message_only(): void
    {
        $response = $this->deleteJson("/api/admin/institutions/{$this->instB->id}");

        $response->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Institution supprimée avec succès']);
    }
}
