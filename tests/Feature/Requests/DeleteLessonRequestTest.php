<?php

namespace Tests\Feature\Requests;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for DeleteLessonRequest (DELETE /api/lessons/{id})
 *
 * Validates:
 * - User authenticated
 * - User is teacher/coordinator/admin
 * - User owns lesson OR is admin
 * - Lesson belongs to user's institution
 *
 * No input validation (delete action only).
 */
class DeleteLessonRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;
    private User $coordinator;
    private Lesson $lesson;

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

        $this->student = User::factory()
            ->student()
            ->for($this->institution)
            ->create();

        $this->lesson = Lesson::factory()
            ->for($this->institution)
            ->state(['enseignant_id' => $this->teacher->id])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Teacher deletes own lesson
     */
    public function test_teacher_can_delete_own_lesson(): void
    {
        Sanctum::actingAs($this->teacher);
        $lessonId = $this->lesson->id;

        $response = $this->deleteJson("/api/lessons/{$lessonId}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('lessons', ['id' => $lessonId]);
    }

    /**
     * ✅ HAPPY PATH: Coordinator can delete any lesson
     */
    public function test_coordinator_can_delete_any_lesson(): void
    {
        Sanctum::actingAs($this->coordinator);
        $lessonId = $this->lesson->id;

        $response = $this->deleteJson("/api/lessons/{$lessonId}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('lessons', ['id' => $lessonId]);
    }

    /**
     * ✅ HAPPY PATH: Admin can delete any lesson
     */
    public function test_admin_can_delete_any_lesson(): void
    {
        $admin = User::factory()
            ->admin()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($admin);
        $lessonId = $this->lesson->id;

        $response = $this->deleteJson("/api/lessons/{$lessonId}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('lessons', ['id' => $lessonId]);
    }

    /**
     * ❌ AUTHORIZATION: Student cannot delete lesson
     */
    public function test_student_cannot_delete_lesson(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->deleteJson("/api/lessons/{$this->lesson->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('lessons', ['id' => $this->lesson->id]);
    }

    /**
     * ❌ AUTHORIZATION: Different teacher cannot delete
     */
    public function test_different_teacher_cannot_delete(): void
    {
        $otherTeacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->deleteJson("/api/lessons/{$this->lesson->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('lessons', ['id' => $this->lesson->id]);
    }

    /**
     * ❌ MULTI-TENANT: Cannot delete lesson from different institution
     */
    public function test_cannot_delete_lesson_from_different_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherTeacher = User::factory()
            ->teacher()
            ->for($otherInstitution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->deleteJson("/api/lessons/{$this->lesson->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('lessons', ['id' => $this->lesson->id]);
    }

    /**
     * ❌ UNAUTHENTICATED: No token fails
     */
    public function test_unauthenticated_cannot_delete(): void
    {
        $response = $this->deleteJson("/api/lessons/{$this->lesson->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('lessons', ['id' => $this->lesson->id]);
    }

    /**
     * ❌ NOT FOUND: Non-existent lesson
     */
    public function test_nonexistent_lesson_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->deleteJson('/api/lessons/99999');

        $response->assertStatus(404);
    }
}
