<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActivateVisioRequestTest extends TestCase
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
            ->create(['klassci_token' => 'token-' . uniqid()]);
        $this->coordinator = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create(['klassci_token' => 'token-' . uniqid()]);
        $this->seance = Seance::factory()
            ->forInstitution($this->institution)
            ->forTeacher($this->teacher->klassci_id)
            ->create();
    }

    public function test_unauthenticated_cannot_activate(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/activate-visio");

        $response->assertStatus(401);
    }

    public function test_student_cannot_activate(): void
    {
        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/activate-visio");

        $response->assertStatus(403);
    }

    public function test_teacher_without_klassci_token_cannot_activate(): void
    {
        $teacherWithoutToken = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create(['klassci_token' => null]);

        Sanctum::actingAs($teacherWithoutToken);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/activate-visio");

        $response->assertStatus(403);
    }

    public function test_teacher_with_token_can_attempt_activation(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/activate-visio");

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    public function test_coordinator_with_token_can_attempt_activation(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/activate-visio");

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }

    public function test_coordinator_without_token_cannot_activate(): void
    {
        $coordWithoutToken = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create(['klassci_token' => null]);

        Sanctum::actingAs($coordWithoutToken);
        $response = $this->postJson("/api/lms/seances/{$this->seance->id}/activate-visio");

        $response->assertStatus(403);
    }

    public function test_authorized_user_passes_formrequest_validation(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/activate-visio");

        $this->assertNotEquals(401, $response->status());
        $this->assertNotEquals(403, $response->status());
    }
}
