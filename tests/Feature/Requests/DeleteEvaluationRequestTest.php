<?php

namespace Tests\Feature\Requests;

use App\Models\Evaluation;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeleteEvaluationRequestTest extends TestCase
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
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
        $this->coordinator = User::factory()->coordinator()->for($this->institution)->create();

        $teacherEnseignantId = data_get($this->teacher->klassci_data, 'enseignant_id');
        $this->evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state(['klassci_enseignant_id' => $teacherEnseignantId])
            ->create();
    }

    public function test_teacher_can_delete(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->withHeader('X-Institution', $this->institution->slug)->deleteJson("/api/evaluations/{$this->evaluation->id}");
        $this->assertTrue(in_array($response->status(), [200, 204]));
    }

    public function test_coordinator_cannot_delete(): void
    {
        Sanctum::actingAs($this->coordinator);
        $response = $this->withHeader('X-Institution', $this->institution->slug)->deleteJson("/api/evaluations/{$this->evaluation->id}");
        $response->assertStatus(403);
    }

    public function test_unauthenticated_cannot_delete(): void
    {
        $response = $this->withHeader('X-Institution', $this->institution->slug)->deleteJson("/api/evaluations/{$this->evaluation->id}");
        $response->assertStatus(401);
    }

    public function test_nonexistent_returns_403(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->withHeader('X-Institution', $this->institution->slug)->deleteJson('/api/evaluations/99999');
        $response->assertStatus(403);
    }
}
