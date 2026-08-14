<?php

declare(strict_types=1);

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests pour GlobalSearchRequest (GET /api/search) — issue #548.
 *
 * Fusionne la règle `query` déjà validée inline dans
 * SearchController::globalSearch — vérifiée ici pour prouver qu'elle survit
 * au déplacement vers le FormRequest.
 */
final class GlobalSearchRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $institution = Institution::factory()->create();

        $this->user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
    }

    public function test_limit_within_bounds_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/search?query=mathematiques&limit=20');

        $response->assertStatus(200);
    }

    public function test_limit_above_max_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/search?query=mathematiques&limit=21');

        $response->assertStatus(422)->assertJsonValidationErrors('limit');
    }

    public function test_query_still_required_and_min_length_2(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/search')
            ->assertStatus(422)->assertJsonValidationErrors('query');

        $this->getJson('/api/search?query=a')
            ->assertStatus(422)->assertJsonValidationErrors('query');
    }
}
