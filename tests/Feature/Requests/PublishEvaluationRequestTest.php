<?php

namespace Tests\Feature\Requests;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublishEvaluationRequestTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    private User $coordinator;

    private Evaluation $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
        $this->coordinator = User::factory()->coordinator()->for($this->institution)->create();

        // Get the teacher's klassci_enseignant_id from klassci_data
        $klassciData = $this->teacher->klassci_data;
        if (is_string($klassciData)) {
            $klassciData = json_decode($klassciData, true);
        }
        $teacherEnseignantId = $klassciData['enseignant_id'] ?? null;

        $this->evaluation = Evaluation::factory()
            ->for($this->institution)
            ->brouillon()
            ->state([
                'klassci_enseignant_id' => $teacherEnseignantId,
                'date_evaluation' => now()->addDays(30),
            ])
            ->create();

        // Create at least one question so evaluation can be published
        EvaluationQuestion::factory()->for($this->evaluation)->create();
    }

    public function test_teacher_can_publish(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/publish");
        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_idempotent(): void
    {
        // Get the teacher's klassci_enseignant_id from klassci_data
        $klassciData = $this->teacher->klassci_data;
        if (is_string($klassciData)) {
            $klassciData = json_decode($klassciData, true);
        }
        $teacherEnseignantId = $klassciData['enseignant_id'] ?? null;
        $published = Evaluation::factory()
            ->for($this->institution)
            ->planifiee()
            ->state([
                'klassci_enseignant_id' => $teacherEnseignantId,
                'date_evaluation' => now()->addDays(30),
            ])
            ->create();

        // Create at least one question
        EvaluationQuestion::factory()->for($published)->create();

        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/evaluations/{$published->id}/publish");
        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_coordinator_cannot_publish(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/publish");
        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_publish(): void
    {
        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/publish");
        $response->assertStatus(401);
    }

    /** #1782 : une évaluation absente est introuvable (404), comme celle d'une autre école. */
    public function test_nonexistent_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/evaluations/99999/publish');
        $response->assertStatus(404);
    }
}
