<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveVisioRequestTest extends TestCase
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
        $this->seance = Seance::factory()
            ->forInstitution($this->institution)
            ->visioActive()
            ->create();
    }

    public function test_unauthenticated_cannot_leave(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/leave");

        $response->assertStatus(401);
    }

    public function test_student_can_leave_active_visio(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/leave");

        $this->assertTrue(in_array($response->status(), [200, 204]));
    }

    public function test_teacher_can_leave_active_visio(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/leave");

        $this->assertTrue(in_array($response->status(), [200, 204]));
    }

    public function test_nonexistent_visio_returns_404(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/lms/seances/99999/leave');

        $response->assertStatus(404);
    }

    public function test_leave_by_local_id(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/leave");

        $this->assertTrue(in_array($response->status(), [200, 204]));
    }

    public function test_coordinator_can_leave_visio(): void
    {
        $coordinator = User::factory()->coordinator()->for($this->institution)->create();
        Sanctum::actingAs($coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/leave");

        $this->assertTrue(in_array($response->status(), [200, 204]));
    }

    public function test_multiple_users_can_leave_same_visio(): void
    {
        Sanctum::actingAs($this->student);
        $response1 = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/leave");

        Sanctum::actingAs($this->teacher);
        $response2 = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/leave");

        $this->assertTrue(in_array($response1->status(), [200, 204]));
        $this->assertTrue(in_array($response2->status(), [200, 204]));
    }
}
