<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HideSeanceRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $student;
    private User $teacher;
    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
        $this->seance = Seance::factory()->forInstitution($this->institution)->create();
    }

    public function test_unauthenticated_cannot_hide(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");

        $response->assertStatus(401);
    }

    public function test_teacher_cannot_hide(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");

        $response->assertStatus(403);
    }

    public function test_student_can_hide(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_nonexistent_seance_returns_404(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/lms/seances/99999/hide');

        $response->assertStatus(404);
    }

    public function test_student_can_hide_by_local_id(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/hide");

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_hiding_same_seance_twice(): void
    {
        Sanctum::actingAs($this->student);

        // First hide
        $response1 = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");
        $this->assertTrue(in_array($response1->status(), [200, 201]));

        // Second hide (idempotent)
        $response2 = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");
        $this->assertTrue(in_array($response2->status(), [200, 201]));
    }

    public function test_coordinator_cannot_hide(): void
    {
        $coordinator = User::factory()->coordinator()->for($this->institution)->create();
        Sanctum::actingAs($coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");

        $response->assertStatus(403);
    }
}
