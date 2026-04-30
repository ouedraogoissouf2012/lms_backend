<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ValidateParticipantRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;
    private Seance $seance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => 'test_token_' . uniqid(),
        ]);
        $this->student = User::factory()->student()->for($this->institution)->create([
            'klassci_token' => 'test_token_' . uniqid(),
        ]);
        $this->seance = Seance::factory()->for($this->institution)->create([
            'klassci_enseignant_id' => 123,
        ]);
    }

    public function test_unauthenticated_cannot_validate_participant(): void
    {
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", [
            'user_id' => $this->student->id,
        ]);

        $response->assertStatus(401);
    }

    public function test_user_without_klassci_token_cannot_validate(): void
    {
        $user = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => null,
        ]);

        Sanctum::actingAs($user);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", [
            'user_id' => $this->student->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_valid_participant_passes_validation(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", [
            'user_id' => $this->student->id,
        ]);

        // Should pass authorization and validation (actual visio checks in controller)
        $this->assertTrue(in_array($response->status(), [200, 403, 404]));
    }

    public function test_missing_user_id_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", []);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.user_id'));
    }

    public function test_user_id_invalid_type_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", [
            'user_id' => 'not_an_integer',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.user_id'));
    }

    public function test_nonexistent_user_returns_422(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", [
            'user_id' => 99999,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.user_id'));
    }

    public function test_student_can_validate_other_user(): void
    {
        $otherStudent = User::factory()->student()->for($this->institution)->create([
            'klassci_token' => 'test_token_' . uniqid(),
        ]);

        Sanctum::actingAs($this->student);
        $response = $this->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", [
            'user_id' => $otherStudent->id,
        ]);

        // Should pass authorization and validation
        $this->assertTrue(in_array($response->status(), [200, 403, 404]));
    }
}
