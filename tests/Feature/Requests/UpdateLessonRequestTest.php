<?php

namespace Tests\Feature\Requests;

use App\Models\Lesson;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for UpdateLessonRequest (PATCH /api/lessons/{id})
 *
 * Validates:
 * - User authenticated (auth:sanctum)
 * - User is teacher/coordinator/admin
 * - User owns lesson OR is admin
 * - Lesson belongs to user's institution (multi-tenant)
 * - Input fields validated individually
 * - Status transitions handle published_at correctly
 *
 * ## 10-year perspective
 * All fields optional (sometimes) for partial updates.
 * Only teacher/admin can modify lessons.
 * Multi-tenant prevents data leaks across institutions.
 */
class UpdateLessonRequestTest extends TestCase
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
                'title' => 'Original Title',
                'status' => 'draft',
            ])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Teacher updates own lesson
     */
    public function test_teacher_can_update_own_lesson(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => 'Updated Title',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $this->assertDatabaseHas('lessons', [
            'id' => $this->lesson->id,
            'title' => 'Updated Title',
        ]);
    }

    /**
     * ✅ HAPPY PATH: Update with multiple fields
     */
    public function test_update_multiple_fields(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => 'New Title',
            'description' => 'New description',
            'type' => 'tp',
            'niveau_difficulte' => 'intermediaire',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $this->assertDatabaseHas('lessons', [
            'id' => $this->lesson->id,
            'title' => 'New Title',
            'description' => 'New description',
            'type' => 'tp',
            'niveau_difficulte' => 'intermediaire',
        ]);
    }

    /**
     * ✅ HAPPY PATH: Coordinator can update any lesson
     */
    public function test_coordinator_can_update_any_lesson(): void
    {
        Sanctum::actingAs($this->coordinator);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => 'Coordinator Update',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $this->assertDatabaseHas('lessons', [
            'title' => 'Coordinator Update',
        ]);
    }

    /**
     * ✅ HAPPY PATH: Status draft → published sets published_at
     */
    public function test_status_draft_to_published_sets_timestamp(): void
    {
        Sanctum::actingAs($this->teacher);
        $this->assertNull($this->lesson->published_at);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'status' => 'published',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($this->lesson->id);
        $this->assertNotNull($lesson->published_at);
        $this->assertEquals('published', $lesson->status);
    }

    /**
     * ✅ HAPPY PATH: Status published → draft clears published_at
     */
    public function test_status_published_to_draft_clears_timestamp(): void
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

        $response = $this->patchJson("/api/lessons/{$publishedLesson->id}", [
            'status' => 'draft',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($publishedLesson->id);
        $this->assertNull($lesson->published_at);
        $this->assertEquals('draft', $lesson->status);
    }

    /**
     * ✅ PARTIAL UPDATE: Only title (other fields unchanged)
     */
    public function test_partial_update_title_only(): void
    {
        Sanctum::actingAs($this->teacher);
        $originalDescription = $this->lesson->description;

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => 'Title Only',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('Title Only', $lesson->title);
        $this->assertEquals($originalDescription, $lesson->description);
    }

    /**
     * ✅ WHITESPACE: Leading/trailing spaces trimmed
     */
    public function test_title_trimmed(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => '   Trimmed Title   ',
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $lesson = Lesson::find($this->lesson->id);
        $this->assertEquals('Trimmed Title', $lesson->title);
    }

    /**
     * ❌ AUTHORIZATION: Student cannot update lesson
     */
    public function test_student_cannot_update_lesson(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
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

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => 'Unauthorized Update',
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ MULTI-TENANT: Cannot update lesson from different institution
     */
    public function test_cannot_update_lesson_from_different_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherTeacher = User::factory()
            ->teacher()
            ->for($otherInstitution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => 'Cross-Tenant Attack',
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ UNAUTHENTICATED: No token fails
     */
    public function test_unauthenticated_cannot_update(): void
    {
        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
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

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
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

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'title' => str_repeat('a', 256),
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.title'));
    }

    /**
     * ❌ VALIDATION: Description too long
     */
    public function test_description_max_1000_characters(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'description' => str_repeat('a', 1001),
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.description'));
    }

    /**
     * ❌ VALIDATION: Invalid type
     */
    public function test_invalid_type(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'type' => 'invalid_type',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.type'));
    }

    /**
     * ❌ VALIDATION: Invalid status
     */
    public function test_invalid_status(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'status' => 'invalid_status',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.status'));
    }

    /**
     * ❌ VALIDATION: Invalid difficulty level
     */
    public function test_invalid_difficulty_level(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'niveau_difficulte' => 'expert',
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.niveau_difficulte'));
    }

    /**
     * ❌ VALIDATION: Duration too long
     */
    public function test_duration_max_1440_minutes(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson("/api/lessons/{$this->lesson->id}", [
            'duree_estimee_minutes' => 1441,
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.duree_estimee_minutes'));
    }

    /**
     * ❌ NOT FOUND: Non-existent lesson
     */
    public function test_nonexistent_lesson_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->patchJson('/api/lessons/99999', [
            'title' => 'Ghost Update',
        ]);

        $response->assertStatus(404);
    }
}
