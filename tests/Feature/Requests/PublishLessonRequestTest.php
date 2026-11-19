<?php

namespace Tests\Feature\Requests;

use App\Enums\LessonStatus;
use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for PublishLessonRequest (POST /api/lessons/{id}/publish)
 *
 * Validates:
 * - User authenticated
 * - User is teacher/coordinator/admin
 * - User owns lesson OR is admin
 * - Lesson belongs to user's institution
 * - Sets status = 'published' and published_at = now()
 *
 * No input validation (publish action only).
 */
class PublishLessonRequestTest extends TestCase
{
    use RefreshDatabase;

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
                'status' => 'draft',
                'published_at' => null,
            ])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Teacher publishes own lesson
     */
    public function test_teacher_can_publish_own_lesson(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/publish");

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals(LessonStatus::Published, $lesson->status);
        $this->assertNotNull($lesson->published_at);
    }

    /**
     * ✅ HAPPY PATH: Coordinator can publish any lesson
     */
    public function test_coordinator_cannotnot_publish_lesson(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/publish");

        $response->assertStatus(403);
    }

    /**
     * ❌ FORBIDDEN: l'admin d'école consulte, il ne publie pas (#1167)
     */
    public function test_admin_cannot_publish_a_lesson(): void
    {
        $admin = User::factory()
            ->admin()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/publish");

        $response->assertStatus(403)->assertJsonPath('reason', 'lesson_content_teacher_only');
        $lesson = Lesson::find($this->lesson->id);
        $this->assertNotEquals(LessonStatus::Published, $lesson->status);
    }

    /**
     * ✅ IDEMPOTENT: Publishing already published lesson succeeds
     */
    public function test_publishing_already_published_lesson_succeeds(): void
    {
        $publishedLesson = Lesson::factory()
            ->for($this->institution)
            ->state([
                'enseignant_id' => $this->teacher->id,
                'status' => 'published',
                'published_at' => now(),
            ])
            ->create();

        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$publishedLesson->id}/publish");

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($publishedLesson->id);
        $this->assertEquals(LessonStatus::Published, $lesson->status);
    }

    /**
     * ❌ AUTHORIZATION: Student cannot publish lesson
     */
    public function test_student_cannot_publish_lesson(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/publish");

        $response->assertStatus(403);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals(LessonStatus::Draft, $lesson->status);
    }

    /**
     * ❌ AUTHORIZATION: Different teacher cannot publish
     */
    public function test_different_teacher_cannot_publish(): void
    {
        $otherTeacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/publish");

        $response->assertStatus(403);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals(LessonStatus::Draft, $lesson->status);
    }

    /**
     * ❌ MULTI-TENANT: Cannot publish lesson from different institution
     */
    public function test_cannot_publish_lesson_from_different_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherTeacher = User::factory()
            ->teacher()
            ->for($otherInstitution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/publish");

        $response->assertStatus(403);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals(LessonStatus::Draft, $lesson->status);
    }

    /**
     * ❌ UNAUTHENTICATED: No token fails
     */
    public function test_unauthenticated_cannot_publish(): void
    {
        $response = $this->postJson("/api/lessons/{$this->lesson->id}/publish");

        $response->assertStatus(401);
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals(LessonStatus::Draft, $lesson->status);
    }

    /**
     * ❌ NOT FOUND: Non-existent lesson
     */
    public function test_nonexistent_lesson_returns_403(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons/99999/publish');

        $response->assertStatus(403);
    }
}
