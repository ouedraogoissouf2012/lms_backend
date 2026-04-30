<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ToggleVisioSeanceRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $coordinator;
    private User $teacher;
    private User $admin;
    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->coordinator = User::factory()->coordinator()->for($this->institution)->create([
            'klassci_token' => 'test_token_' . uniqid(),
        ]);
        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => 'test_token_' . uniqid(),
        ]);
        $this->admin = User::factory()->admin()->for($this->institution)->create([
            'klassci_token' => 'test_token_' . uniqid(),
        ]);
        $this->seance = Seance::factory()->for($this->institution)->create();
    }

    public function test_unauthenticated_cannot_toggle_visio(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => true,
        ]);

        $response->assertStatus(401);
    }

    public function test_teacher_cannot_toggle_visio(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_coordinator_can_toggle_visio(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => true,
            'visio_type' => 'jitsi',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_admin_can_toggle_visio(): void
    {
        Sanctum::actingAs($this->admin);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => false,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_coordinator_without_klassci_token_cannot_toggle(): void
    {
        $coordinatorNoToken = User::factory()->coordinator()->for($this->institution)->create([
            'klassci_token' => null,
        ]);

        Sanctum::actingAs($coordinatorNoToken);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => true,
        ]);

        $response->assertStatus(403);
    }

    public function test_missing_enabled_returns_422(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", []);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.enabled'));
    }

    public function test_invalid_enabled_type_returns_422(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => 'yes',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.enabled'));
    }

    public function test_invalid_visio_type_returns_422(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => true,
            'visio_type' => 'invalid_type',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.visio_type'));
    }

    public function test_enabled_with_valid_visio_type(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => true,
            'visio_type' => 'zoom',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_disabled_without_visio_type(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/toggle-visio", [
            'enabled' => false,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }
}
