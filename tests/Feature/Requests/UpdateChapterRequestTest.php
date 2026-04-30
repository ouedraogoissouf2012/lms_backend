<?php

namespace Tests\Feature\Requests;

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for UpdateChapterRequest (PATCH /api/chapters/{id})
 *
 * Validates:
 * - User authenticated
 * - User is teacher/coordinator/admin
 * - User owns chapter's lesson
 * - Chapter belongs to user's institution
 * - Input fields validated
 */
class UpdateChapterRequestTest extends TestCase
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
                'title' => 'Original Title',
            ])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Teacher updates chapter in own lesson
     */
    public function test_teacher_can_update_chapter(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'Updated Title',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $this->assertDatabaseHas('chapters', [
            'id' => $this->chapter->id,
            'title' => 'Updated Title',
        ]);
    }

    /**
     * ✅ HAPPY PATH: Update multiple fields
     */
    public function test_update_multiple_fields(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'New Title',
            'description' => 'New description',
            'video_url' => 'https://youtube.com/watch?v=123',
            'order' => 5,
            'duration_minutes' => 30,
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $this->assertDatabaseHas('chapters', [
            'id' => $this->chapter->id,
            'title' => 'New Title',
            'description' => 'New description',
            'video_url' => 'https://youtube.com/watch?v=123',
            'order' => 5,
            'duration_minutes' => 30,
        ]);
    }

    /**
     * ✅ HAPPY PATH: Partial update (title only)
     */
    public function test_partial_update(): void
    {
        Sanctum::actingAs($this->teacher);
        $originalDescription = $this->chapter->description;

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'Only Title Changed',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $chapter = Chapter::find($this->chapter->id);
        $this->assertEquals('Only Title Changed', $chapter->title);
        $this->assertEquals($originalDescription, $chapter->description);
    }

    /**
     * ✅ WHITESPACE: Title trimmed
     */
    public function test_title_trimmed(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => '   Trimmed   ',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $chapter = Chapter::find($this->chapter->id);
        $this->assertEquals('Trimmed', $chapter->title);
    }

    /**
     * ❌ AUTHORIZATION: Student cannot update chapter
     */
    public function test_student_cannot_update_chapter(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'Student Attempt',
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ AUTHORIZATION: Different teacher cannot update
     */
    public function test_different_teacher_cannot_update(): void
    {
        $otherTeacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'Unauthorized',
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ MULTI-TENANT: Cannot update chapter from different institution
     */
    public function test_cannot_update_from_different_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherTeacher = User::factory()
            ->teacher()
            ->for($otherInstitution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'Cross-Tenant',
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ UNAUTHENTICATED
     */
    public function test_unauthenticated_cannot_update(): void
    {
        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'No Token',
        ]);

        $response->assertStatus(401);
    }

    /**
     * ❌ VALIDATION: Title too short
     */
    public function test_title_min_3_characters(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => 'ab',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.title'));
    }

    /**
     * ❌ VALIDATION: Title too long
     */
    public function test_title_max_255_characters(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'title' => str_repeat('a', 256),
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.title'));
    }

    /**
     * ❌ VALIDATION: Invalid URL
     */
    public function test_invalid_video_url(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/chapters/{$this->chapter->id}", [
            'video_url' => 'not-a-url',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.video_url'));
    }

    /**
     * ❌ NOT FOUND
     */
    public function test_nonexistent_chapter_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson('/api/chapters/99999', [
            'title' => 'Ghost',
        ]);

        $response->assertStatus(404);
    }
}
