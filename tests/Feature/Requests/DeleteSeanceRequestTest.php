<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeleteSeanceRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $otherTeacher;
    private User $coordinator;
    private User $student;
    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_id' => 100,
        ]);
        $this->otherTeacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_id' => 101,
        ]);
        $this->coordinator = User::factory()->coordinator()->for($this->institution)->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
        $this->seance = Seance::factory()
            ->forInstitution($this->institution)
            ->create([
                'klassci_enseignant_id' => 100,
            ]);
    }

    public function test_unauthenticated_cannot_delete(): void
    {
        $response = $this->deleteJson("/api/lms/seances/{$this->seance->klassci_seance_id}");

        $response->assertStatus(401);
    }

    public function test_student_cannot_delete(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->deleteJson("/api/lms/seances/{$this->seance->klassci_seance_id}");

        $response->assertStatus(403);
    }

    public function test_teacher_can_delete_own_seance(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->deleteJson("/api/lms/seances/{$this->seance->klassci_seance_id}");

        $this->assertTrue(in_array($response->status(), [200, 201, 204]));
    }

    public function test_teacher_cannot_delete_others_seance(): void
    {
        Sanctum::actingAs($this->otherTeacher);
        $response = $this->deleteJson("/api/lms/seances/{$this->seance->klassci_seance_id}");

        $response->assertStatus(403);
    }

    public function test_coordinator_can_delete_any_seance(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->deleteJson("/api/lms/seances/{$this->seance->klassci_seance_id}");

        $this->assertTrue(in_array($response->status(), [200, 201, 204]));
    }

    public function test_nonexistent_returns_403(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->deleteJson('/api/lms/seances/99999');

        $response->assertStatus(403);
    }

    public function test_teacher_can_delete_by_local_id(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->deleteJson("/api/lms/seances/{$this->seance->id}");

        $this->assertTrue(in_array($response->status(), [200, 201, 204]));
    }

    public function test_seance_without_klassci_enseignant_id_can_be_deleted_by_any_teacher(): void
    {
        // Create seance without klassci_enseignant_id
        $seanceNoOwner = Seance::factory()
            ->forInstitution($this->institution)
            ->create([
                'klassci_enseignant_id' => null,
            ]);

        Sanctum::actingAs($this->otherTeacher);
        $response = $this->deleteJson("/api/lms/seances/{$seanceNoOwner->klassci_seance_id}");

        // Should allow deletion since no owner is set
        $this->assertTrue(in_array($response->status(), [200, 201, 204]));
    }

    public function test_multiple_teachers_cannot_delete_same_seance(): void
    {
        Sanctum::actingAs($this->otherTeacher);
        $response = $this->deleteJson("/api/lms/seances/{$this->seance->klassci_seance_id}");

        $response->assertStatus(403);

        // Verify seance still exists
        $this->assertNotNull(Seance::find($this->seance->id));
    }
}
