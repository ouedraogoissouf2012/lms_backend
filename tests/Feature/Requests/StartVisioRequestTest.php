<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StartVisioRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $student;
    private User $teacher;
    private User $coordinator;
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
            ->create();
        $this->coordinator = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create();
        $this->seance = Seance::factory()
            ->forInstitution($this->institution)
            ->withVisio()
            ->create();
    }

    public function test_unauthenticated_cannot_start(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/start-visio");

        $response->assertStatus(401);
    }

    public function test_student_cannot_start(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/start-visio");

        $response->assertStatus(403);
    }

    public function test_teacher_with_role_passes_authorization(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/start-visio");

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    public function test_teacher_can_start_any_seance(): void
    {
        $otherSeance = Seance::factory()
            ->forInstitution($this->institution)
            ->withVisio()
            ->create();

        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$otherSeance->id}/start-visio");

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    public function test_coordinator_can_attempt_start(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/start-visio");

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    public function test_authorized_user_by_local_id(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/start-visio");

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }
}
