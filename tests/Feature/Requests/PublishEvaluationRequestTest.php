<?php

namespace Tests\Feature\Requests;

use App\Models\Evaluation;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PublishEvaluationRequestTest extends TestCase
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
        $this->evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state(['is_published' => false])
            ->create();
    }

    public function test_teacher_can_publish(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/publish");
        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    public function test_idempotent(): void
    {
        $published = Evaluation::factory()
            ->for($this->institution)
            ->state(['is_published' => true])
            ->create();

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

    public function test_nonexistent_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);
        $response = $this->postJson('/api/evaluations/99999/publish');
        $response->assertStatus(404);
    }
}
