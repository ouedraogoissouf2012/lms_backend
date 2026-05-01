<?php

namespace Tests\Feature\Requests;

use App\Models\Evaluation;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for UpdateEvaluationRequest (PATCH /api/evaluations/{id})
 */
class UpdateEvaluationRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $coordinator;
    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();

        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        $this->coordinator = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create();

        $teacherEnseignantId = data_get($this->teacher->klassci_data, 'enseignant_id');
        $this->evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state([
                'klassci_enseignant_id' => $teacherEnseignantId,
                'titre' => 'Original Title',
            ])
            ->create();
    }

    /** ✅ Teacher updates own evaluation */
    public function test_teacher_can_update(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/evaluations/{$this->evaluation->id}", [
            'titre' => 'Updated Title',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /** ✅ Update multiple fields */
    public function test_update_multiple_fields(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/evaluations/{$this->evaluation->id}", [
            'titre' => 'New Title',
            'description' => 'New description',
            'duree_minutes' => 90,
            'is_published' => true,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /** ✅ Partial update */
    public function test_partial_update(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/evaluations/{$this->evaluation->id}", [
            'titre' => 'Only Title',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /** ❌ Coordinator cannot update */
    public function test_coordinator_cannot_update(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->patchJson("/api/evaluations/{$this->evaluation->id}", [
            'titre' => 'Coordinator Attempt',
        ]);

        $response->assertStatus(403);
    }

    /** ❌ Unauthenticated */
    public function test_unauthenticated_cannot_update(): void
    {
        $response = $this->patchJson("/api/evaluations/{$this->evaluation->id}", [
            'titre' => 'No Token',
        ]);

        $response->assertStatus(401);
    }

    /** ❌ Invalid status */
    public function test_invalid_status(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/evaluations/{$this->evaluation->id}", [
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422);
    }

    /** ❌ Titre too short */
    public function test_titre_too_short(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/evaluations/{$this->evaluation->id}", [
            'titre' => 'ab',
        ]);

        $response->assertStatus(422);
    }

    /** ❌ Not found */
    public function test_nonexistent_returns_403(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson('/api/evaluations/99999', [
            'titre' => 'Ghost',
        ]);

        $response->assertStatus(403);
    }
}
