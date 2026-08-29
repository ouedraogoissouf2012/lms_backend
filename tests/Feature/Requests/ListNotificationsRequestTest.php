<?php

declare(strict_types=1);

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests pour ListNotificationsRequest (GET /notifications) et
 * RecentNotificationsRequest (GET /notifications/recent) — issue #548.
 */
final class ListNotificationsRequestTest extends TestCase
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

    public function test_index_per_page_within_bounds_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications?per_page=100');

        $response->assertStatus(200);
    }

    public function test_index_per_page_above_max_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications?per_page=101');

        $response->assertStatus(422)->assertJsonValidationErrors('per_page');
    }

    public function test_recent_limit_within_bounds_is_accepted(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications/recent?limit=20');

        $response->assertStatus(200);
    }

    public function test_recent_limit_above_max_is_rejected(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson('/api/notifications/recent?limit=21');

        $response->assertStatus(422)->assertJsonValidationErrors('limit');
    }
}
