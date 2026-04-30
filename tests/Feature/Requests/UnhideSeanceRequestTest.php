<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnhideSeanceRequestTest extends TestCase
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

    public function test_unauthenticated_cannot_unhide(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $response->assertStatus(401);
    }

    public function test_teacher_cannot_unhide(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $response->assertStatus(403);
    }

    public function test_student_can_unhide_hidden_seance(): void
    {
        // First hide the seance
        SeanceUserHidden::hide($this->seance->id, $this->student->id);

        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_unhide_nonexistent_seance_returns_404(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson('/api/lms/seances/99999/unhide');

        $response->assertStatus(404);
    }

    public function test_unhide_not_hidden_seance_returns_404(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        // Should return 404 because seance wasn't hidden for this student
        $response->assertStatus(404);
    }

    public function test_student_can_unhide_by_local_id(): void
    {
        SeanceUserHidden::hide($this->seance->id, $this->student->id);

        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/unhide");

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_hide_then_unhide_cycle(): void
    {
        Sanctum::actingAs($this->student);

        // Hide seance
        $hideResponse = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/hide");
        $this->assertTrue(in_array($hideResponse->status(), [200, 201]));

        // Verify it's hidden
        $this->assertTrue(SeanceUserHidden::isHidden($this->seance->id, $this->student->id));

        // Unhide seance
        $unhideResponse = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");
        $this->assertTrue(in_array($unhideResponse->status(), [200, 201]));

        // Verify it's not hidden anymore
        $this->assertFalse(SeanceUserHidden::isHidden($this->seance->id, $this->student->id));
    }

    public function test_coordinator_cannot_unhide(): void
    {
        $coordinator = User::factory()->coordinator()->for($this->institution)->create();
        SeanceUserHidden::hide($this->seance->id, $coordinator->id);

        Sanctum::actingAs($coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/unhide");

        $response->assertStatus(403);
    }
}
