<?php

declare(strict_types=1);

namespace Tests\Feature\Institution;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sécurité — le token API KLASSCI (`klassci_api_token_encrypted`, cast `encrypted`
 * donc déchiffré à l'accès) ne doit JAMAIS apparaître dans une réponse API, même
 * pour un supradmin. `InstitutionController::store/update/toggle` renvoient le
 * modèle complet : sans `$hidden`, le token fuyait en clair.
 *
 * @see \App\Models\Institution
 */
final class InstitutionTokenExposureTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'super-secret-klassci-token-xyz';

    public function test_token_is_hidden_from_model_serialization(): void
    {
        $institution = Institution::factory()->create();
        $institution->klassci_api_token = self::SECRET;
        $institution->save();

        $fresh = $institution->fresh();
        $array = $fresh->toArray();

        $this->assertArrayNotHasKey('klassci_api_token_encrypted', $array);
        $this->assertArrayNotHasKey('klassci_api_token', $array);
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($array));

        // Accès interne (KlassciProxyService) inchangé : la valeur reste lisible.
        $this->assertSame(self::SECRET, $fresh->klassci_api_token);
    }

    public function test_store_endpoint_does_not_leak_token_to_supradmin(): void
    {
        $institution = Institution::factory()->create();
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'supradmin',
        ]));

        $response = $this->postJson('/api/admin/institutions', [
            'slug' => 'ecole-test',
            'name' => 'École Test',
            'klassci_api_url' => 'https://klassci.example.test',
            'klassci_api_token' => self::SECRET,
        ]);

        $response->assertStatus(201);
        $this->assertStringNotContainsString(self::SECRET, $response->getContent() ?: '');
        $this->assertArrayNotHasKey('klassci_api_token_encrypted', (array) $response->json('data'));
    }
}
