<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Caractérisation (axe #1) — fige le comportement EXACT des 4 endpoints de
 * {@see \App\Http\Controllers\API\IntegrationController} AVANT la migration
 * vers le trait {@see \App\Http\Controllers\Concerns\RespondsWithJson}.
 *
 * ## Découverte — 2 endpoints renvoient 500 (bug latent pré-existant)
 *
 * `testConnection` et `disconnect` lisent `$data['service']` issu de
 * `$request->validated()`, mais leurs FormRequests (`TestServiceConnectionRequest`,
 * `DisconnectServiceRequest`) ont `rules() === []`. `validated()` renvoie donc
 * `[]`, l'accès `$data['service']` déclenche un warning « Undefined array key »
 * que Laravel convertit en `ErrorException` → réponse 500 `{success:false}`.
 *
 * Ces 2 endpoints sont des stubs (ils n'appellent aucun service/persistance) et
 * ne sont pas couverts par un test : le bug n'a jamais été observé. La migration
 * d'enveloppe préserve ce comportement à l'identique (l'accès `$data['service']`
 * reste, donc le 500 reste) — le correctif de validation est HORS périmètre de
 * l'axe #1 (« DRY-only », sortie strictement identique) et tracé pour un
 * follow-up. Ce test verrouille le comportement courant.
 *
 * Auth : supradmin (passe `role:superAdmin` ET `authorize()` qui exige
 * `asRoleEnum() === Role::Supradmin`).
 *
 * @see app/Http/Controllers/API/IntegrationController.php
 */
final class IntegrationResponseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create(['slug' => 'school-a']);
        Sanctum::actingAs(User::factory()->create([
            'institution_id' => $institution->id,
            'role'           => 'supradmin',
        ]));
    }

    public function test_connect_returns_exact_success_envelope(): void
    {
        $response = $this->postJson('/api/integrations/connect', [
            'service' => 'slack',
            'api_key' => 'abcdefghij1234',
        ]);

        $response->assertStatus(201)->assertExactJson([
            'success' => true,
            'message' => 'Service connecté avec succès',
            'data'    => [
                'service' => 'slack',
                'status'  => 'connected',
                'api_key' => 'abcdefghij****',
            ],
        ]);
    }

    public function test_authorize_returns_exact_success_envelope(): void
    {
        $response = $this->postJson('/api/integrations/authorize', [
            'service'   => 'slack',
            'auth_code' => 'oauth-code-123',
        ]);

        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => 'Service autorisé avec succès',
            'data'    => [
                'service' => 'slack',
                'status'  => 'authorized',
            ],
        ]);
    }

    /**
     * Bug latent (cf. docblock de classe) : 500 car `service` non validé.
     */
    public function test_test_connection_currently_errors_on_unvalidated_service(): void
    {
        $response = $this->postJson('/api/integrations/test', ['service' => 'slack']);

        $response->assertStatus(500)->assertJsonPath('success', false);
    }

    /**
     * Bug latent (cf. docblock de classe) : 500 car `service` non validé.
     */
    public function test_disconnect_currently_errors_on_unvalidated_service(): void
    {
        $response = $this->postJson('/api/integrations/disconnect', ['service' => 'slack']);

        $response->assertStatus(500)->assertJsonPath('success', false);
    }
}
