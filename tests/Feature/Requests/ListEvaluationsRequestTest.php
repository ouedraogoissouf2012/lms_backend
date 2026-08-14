<?php

declare(strict_types=1);

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests pour ListEvaluationsRequest (GET /api/evaluations) — issue #548.
 *
 * /api/evaluations n'a aucune pagination réelle (->get() brut). Le paramètre
 * ajouté s'appelle `limit`, pas `per_page`, pour ne pas laisser croire à une
 * enveloppe de pagination qui n'existe pas — le frontend fait
 * `result.data.map(...)` sur un tableau plat (lms-frontend/src/services/
 * evaluation.js), la forme de réponse ne doit donc pas changer.
 */
final class ListEvaluationsRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();

        $this->teacher = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
            'klassci_role' => 'enseignant',
            'klassci_enseignant_id' => 42,
        ]);
    }

    public function test_limit_within_bounds_is_accepted(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/evaluations?limit=100');

        $response->assertStatus(200);
    }

    public function test_limit_above_max_is_rejected(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/evaluations?limit=101');

        $response->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    public function test_response_shape_stays_a_flat_array_not_a_paginator_envelope(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/evaluations');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertIsArray($body['data']);
        $this->assertArrayNotHasKey('current_page', $body);
        $this->assertArrayNotHasKey('current_page', $body['data']);
    }
}
