<?php

declare(strict_types=1);

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests pour ListForumTopicsRequest (GET /api/forum/topics) — issue #548.
 */
final class ListForumTopicsRequestTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();

        $this->user = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'etudiant',
        ]);
    }

    public function test_per_page_within_bounds_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/forum/topics?per_page=100');

        $response->assertStatus(200);
    }

    public function test_per_page_above_max_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/forum/topics?per_page=101');

        $response->assertStatus(422)->assertJsonValidationErrors('per_page');
    }
}
