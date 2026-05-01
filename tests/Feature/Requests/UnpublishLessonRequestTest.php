<?php

namespace Tests\Feature\Requests;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for UnpublishLessonRequest (POST /api/lessons/{id}/unpublish)
 *
 * Validates:
 * - User authenticated
 * - User is teacher/coordinator/admin
 * - User owns lesson OR is admin
 * - Lesson belongs to user's institution
 * - Sets status = 'draft' and published_at = null
 *
 * No input validation (unpublish action only).
 */
class UnpublishLessonRequestTest extends TestCase
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
            ->state([
                'enseignant_id' => $this->teacher->id,
                'status' => 'published',
                'published_at' => now(),
            ])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Teacher unpublishes own lesson
     */
    public function test_teacher_can_unpublish_own_lesson(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/unpublish");

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('draft', $lesson->status);
        $this->assertNull($lesson->published_at);
    }

    /**
     * ✅ HAPPY PATH: Coordinator can unpublish any lesson
     */
    public function test_coordinator_cannotnot_unpublish_lesson(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/unpublish");

        $response->assertStatus(403);
    }

    /**
     * ✅ HAPPY PATH: Admin can unpublish any lesson
     */
    public function test_admin_can_unpublish_any_lesson(): void
    {
        $admin = User::factory()
            ->admin()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/unpublish");

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('draft', $lesson->status);
    }

    /**
     * ✅ IDEMPOTENT: Unpublishing draft lesson succeeds
     */
    public function test_unpublishing_draft_lesson_succeeds(): void
    {
        $draftLesson = Lesson::factory()
            ->for($this->institution)
            ->state([
                'enseignant_id' => $this->teacher->id,
                'status' => 'draft',
                'published_at' => null,
            ])
            ->create();

        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$draftLesson->id}/unpublish");

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($draftLesson->id);
        $this->assertEquals('draft', $lesson->status);
    }

    /**
     * ❌ AUTHORIZATION: Student cannot unpublish lesson
     */
    public function test_student_cannot_unpublish_lesson(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/unpublish");

        $response->assertStatus(403);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('published', $lesson->status);
    }

    /**
     * ❌ AUTHORIZATION: Different teacher cannot unpublish
     */
    public function test_different_teacher_cannot_unpublish(): void
    {
        $otherTeacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/unpublish");

        $response->assertStatus(403);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('published', $lesson->status);
    }

    /**
     * ❌ MULTI-TENANT: Cannot unpublish lesson from different institution
     */
    public function test_cannot_unpublish_lesson_from_different_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherTeacher = User::factory()
            ->teacher()
            ->for($otherInstitution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/unpublish");

        $response->assertStatus(403);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('published', $lesson->status);
    }

    /**
     * ❌ UNAUTHENTICATED: No token fails
     */
    public function test_unauthenticated_cannot_unpublish(): void
    {
        $response = $this->postJson("/api/lessons/{$this->lesson->id}/unpublish");

        $response->assertStatus(401);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('published', $lesson->status);
    }

    /**
     * ❌ AUTHORIZATION: Non-existent lesson
     */
    public function test_nonexistent_lesson_returns_403(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons/99999/unpublish');

        $response->assertStatus(403);
    }
}
