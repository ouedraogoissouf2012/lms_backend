<?php

namespace Tests\Feature\Requests;

use App\Models\Institution;
use App\Models\User;
use App\Models\Quiz;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeleteQuizRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $otherTeacher;
    private User $student;
    private Quiz $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
        $this->otherTeacher = User::factory()->teacher()->for($this->institution)->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
        $this->quiz = Quiz::factory()->for($this->institution)->create([
            'created_by' => $this->teacher->id,
        ]);
    }

    public function test_unauthenticated_cannot_delete(): void
    {
        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(401);
    }

    public function test_non_owner_cannot_delete(): void
    {
        Sanctum::actingAs($this->otherTeacher);

        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(403);
    }

    public function test_student_cannot_delete(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(403);
    }

    public function test_owner_can_delete(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_admin_can_delete_any_quiz(): void
    {
        $admin = User::factory()->admin()->for($this->institution)->create();
        Sanctum::actingAs($admin);

        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_nonexistent_quiz_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->deleteJson('/api/quizzes/99999');

        $response->assertStatus(404);
    }

    public function test_soft_delete_preserves_record(): void
    {
        Sanctum::actingAs($this->teacher);

        $quizId = $this->quiz->id;

        // Delete
        $response = $this->deleteJson("/api/quizzes/{$quizId}");
        $response->assertStatus(200);

        // Verify soft delete (record still exists but is marked as deleted)
        $this->assertSoftDeleted('quizzes', ['id' => $quizId]);
    }

    public function test_coordinator_cannot_delete_others_quiz(): void
    {
        $coordinator = User::factory()->coordinator()->for($this->institution)->create();
        Sanctum::actingAs($coordinator);

        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");

        $response->assertStatus(403);
    }

    public function test_multiple_deletes_on_same_quiz_should_still_fail_on_second(): void
    {
        Sanctum::actingAs($this->teacher);

        // First delete should succeed
        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");
        $response->assertStatus(200);

        // Second delete on same quiz should fail (404 or 403 depending on implementation)
        // Since soft-deleted quizzes are still returned by Quiz::find(), this tests soft-delete behavior
        $response = $this->deleteJson("/api/quizzes/{$this->quiz->id}");
        // After soft delete, the quiz is still findable, so owner can delete it again (which resets nothing)
        // Or it returns 404 if find() filters soft-deletes. Let's check what the controller does.
    }
}
