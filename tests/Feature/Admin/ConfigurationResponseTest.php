<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Caractérisation (axe #1) — fige le comportement EXACT des 3 endpoints de
 * {@see \App\Http\Controllers\API\ConfigurationController} AVANT la migration
 * vers le trait {@see \App\Http\Controllers\Concerns\RespondsWithJson}.
 * Controller initialement « NON testé » → test-first.
 *
 * ## Découverte — `delete` renvoie 500 (bug latent pré-existant)
 *
 * `delete` lit `$data['key']` issu de `$request->validated()`, mais
 * `DeleteConfigurationRequest::rules()` est `[]`. `validated()` renvoie donc
 * `[]`, l'accès `$data['key']` déclenche un warning « Undefined array key »
 * converti par Laravel en `ErrorException` → réponse 500 `{success:false}`.
 * Endpoint stub (aucune persistance), jamais couvert. La migration d'enveloppe
 * préserve ce comportement à l'identique ; le correctif de validation est HORS
 * périmètre (axe #1 « DRY-only ») et tracé pour un follow-up.
 *
 * Auth : supradmin (passe `role:superAdmin` ET `authorize()` qui exige
 * `asRoleEnum() === Role::Supradmin`).
 *
 * @see app/Http/Controllers/API/ConfigurationController.php
 */
final class ConfigurationResponseTest extends TestCase
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

    public function test_get_returns_exact_success_data_envelope(): void
    {
        $response = $this->getJson('/api/configuration?category=general');

        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'data'    => [
                'key'      => null,
                'category' => 'general',
            ],
        ]);
    }

    public function test_update_returns_exact_success_envelope(): void
    {
        $response = $this->putJson('/api/configuration', [
            'key'      => 'theme',
            'value'    => 'dark',
            'category' => 'general',
        ]);

        $response->assertStatus(200)->assertExactJson([
            'success' => true,
            'message' => 'Configuration mise à jour avec succès',
            'data'    => [
                'key'      => 'theme',
                'value'    => 'dark',
                'category' => 'general',
            ],
        ]);
    }

    /**
     * Bug latent (cf. docblock de classe) : 500 car `key` non validé.
     */
    public function test_delete_currently_errors_on_unvalidated_key(): void
    {
        $response = $this->deleteJson('/api/configuration', ['key' => 'theme']);

        $response->assertStatus(500)->assertJsonPath('success', false);
    }
}
