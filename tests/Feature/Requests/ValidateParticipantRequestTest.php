<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\Seance;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

class ValidateParticipantRequestTest extends TestCase
{
    use ActsAsTenantUser;
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
        $this->seance = Seance::factory()->forInstitution($this->institution)->create([
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

    /**
     * Sans jeton, la garde ne refuse plus : la validation lit des données
     * locales (#712). C'est le SERVICE qui répond — ici, la séance n'a pas de
     * visio. Vrai jeton porteur (`asTenant`).
     */
    public function test_user_without_klassci_token_is_judged_by_the_service(): void
    {
        $user = User::factory()->teacher()->for($this->institution)->create([
            'klassci_token' => null,
        ]);

        $this->asTenant($user)
            ->postJson("/api/lms/seances/{$this->seance->klassci_seance_id}/validate-participant", [
                'user_id' => $this->student->id,
            ])
            ->assertForbidden()
            ->assertExactJson([
                'success' => false,
                'authorized' => false,
                'reason' => 'visio_not_enabled',
                'message' => 'Visioconférence non activée pour cette séance',
            ]);
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
