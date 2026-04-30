<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeactivateVisioRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $student;
    private User $teacher;
    private User $otherTeacher;
    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create(['klassci_id' => 'teacher-1']);
        $this->otherTeacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create(['klassci_id' => 'teacher-2']);
        $this->seance = Seance::factory()
            ->forInstitution($this->institution)
            ->forTeacher($this->teacher)
            ->withVisio()
            ->create();
    }

    public function test_unauthenticated_cannot_deactivate(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/deactivate-visio");

        $response->assertStatus(401);
    }

    public function test_student_cannot_deactivate(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/deactivate-visio");

        $response->assertStatus(403);
    }

    public function test_teacher_can_deactivate_own_seance(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/deactivate-visio");

        $response->assertStatus(200);
    }

    public function test_teacher_cannot_deactivate_other_teacher_seance(): void
    {
        Sanctum::actingAs($this->otherTeacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/deactivate-visio");

        $response->assertStatus(403);
    }

    public function test_coordinator_cannot_deactivate(): void
    {
        $coordinator = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/deactivate-visio");

        $response->assertStatus(403);
    }

    public function test_nonexistent_seance_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/lms/seances/99999/deactivate-visio');

        $response->assertStatus(404);
    }

    public function test_deactivate_by_klassci_seance_id(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/deactivate-visio");

        $response->assertStatus(200);
    }

    public function test_teacher_without_ownership_returns_403(): void
    {
        $seanceWithoutOwner = Seance::factory()
            ->forInstitution($this->institution)
            ->create(['klassci_enseignant_id' => 'someone-else']);

        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$seanceWithoutOwner->id}/deactivate-visio");

        $response->assertStatus(403);
    }
}
