<?php

namespace Tests\Feature\Requests;

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for DeleteChapterRequest (DELETE /api/chapters/{id})
 *
 * Validates authorization to delete chapter from lesson.
 */
class DeleteChapterRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;
    private Lesson $lesson;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();

        $this->teacher = User::factory()
            ->teacher()
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

        $this->chapter = Chapter::factory()
            ->for($this->institution)
            ->state([
                'lesson_id' => $this->lesson->id,
                'enseignant_id' => $this->teacher->id,
            ])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Teacher deletes chapter from own lesson
     */
    public function test_teacher_can_delete_chapter(): void
    {
        Sanctum::actingAs($this->teacher);
        $chapterId = $this->chapter->id;

        $response = $this->deleteJson("/api/chapters/{$chapterId}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('chapters', ['id' => $chapterId]);
    }

    /**
     * ❌ FORBIDDEN: Coordinator cannot delete chapter (only owner or admin)
     */
    public function test_coordinator_cannotnot_delete_chapter(): void
    {
        $coordinator = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($coordinator);
        $chapterId = $this->chapter->id;

        $response = $this->deleteJson("/api/chapters/{$chapterId}");

        $response->assertStatus(403);
    }

    /**
     * ❌ AUTHORIZATION: Student cannot delete
     */
    public function test_student_cannot_delete_chapter(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->deleteJson("/api/chapters/{$this->chapter->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('chapters', ['id' => $this->chapter->id]);
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

        $response = $this->deleteJson("/api/chapters/{$this->chapter->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('chapters', ['id' => $this->chapter->id]);
    }

    /**
     * ❌ MULTI-TENANT: Cannot delete from different institution
     */
    public function test_cannot_delete_from_different_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherTeacher = User::factory()
            ->teacher()
            ->for($otherInstitution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->deleteJson("/api/chapters/{$this->chapter->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('chapters', ['id' => $this->chapter->id]);
    }

    /**
     * ❌ UNAUTHENTICATED
     */
    public function test_unauthenticated_cannot_delete(): void
    {
        $response = $this->deleteJson("/api/chapters/{$this->chapter->id}");

        $response->assertStatus(401);
        $this->assertDatabaseHas('chapters', ['id' => $this->chapter->id]);
    }

    /**
     * ❌ NOT FOUND
     */
    public function test_nonexistent_chapter_returns_403(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->deleteJson('/api/chapters/99999');

        $response->assertStatus(403);
    }
}
