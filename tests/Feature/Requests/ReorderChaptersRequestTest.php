<?php

namespace Tests\Feature\Requests;

use App\Models\Chapter;
use App\Models\Lesson;
use App\Models\User;
use App\Models\Institution;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for ReorderChaptersRequest (POST /api/lessons/{lessonId}/chapters/reorder)
 *
 * Validates:
 * - User authenticated
 * - User is teacher/coordinator/admin
 * - User owns the lesson
 * - Lesson belongs to user's institution
 * - Array of chapters with id + order
 */
class ReorderChaptersRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;
    private Lesson $lesson;
    private array $chapters;

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

        // Create 3 chapters
        $this->chapters = [];
        for ($i = 0; $i < 3; $i++) {
            $this->chapters[] = Chapter::factory()
                ->for($this->institution)
                ->state([
                    'lesson_id' => $this->lesson->id,
                    'enseignant_id' => $this->teacher->id,
                    'order' => $i,
                ])
                ->create();
        }
    }

    /**
     * ✅ HAPPY PATH: Teacher reorders chapters
     */
    public function test_teacher_can_reorder_chapters(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 2],
                ['id' => $this->chapters[1]->id, 'order' => 0],
                ['id' => $this->chapters[2]->id, 'order' => 1],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
        $this->assertDatabaseHas('chapters', ['id' => $this->chapters[0]->id, 'order' => 2]);
        $this->assertDatabaseHas('chapters', ['id' => $this->chapters[1]->id, 'order' => 0]);
        $this->assertDatabaseHas('chapters', ['id' => $this->chapters[2]->id, 'order' => 1]);
    }

    /**
     * ✅ HAPPY PATH: Coordinator can reorder any lesson's chapters
     */
    public function test_coordinator_can_reorder_chapters(): void
    {
        $coordinator = User::factory()
            ->coordinator()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($coordinator);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 1],
                ['id' => $this->chapters[1]->id, 'order' => 2],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ✅ REORDER SUBSET: Only 2 of 3 chapters provided
     */
    public function test_reorder_subset_of_chapters(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 1],
                ['id' => $this->chapters[1]->id, 'order' => 0],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [200, 201]));
    }

    /**
     * ❌ AUTHORIZATION: Student cannot reorder
     */
    public function test_student_cannot_reorder(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ AUTHORIZATION: Different teacher cannot reorder
     */
    public function test_different_teacher_cannot_reorder(): void
    {
        $otherTeacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ MULTI-TENANT: Cannot reorder from different institution
     */
    public function test_cannot_reorder_from_different_institution(): void
    {
        $otherInstitution = Institution::factory()->create();
        $otherTeacher = User::factory()
            ->teacher()
            ->for($otherInstitution)
            ->create();

        Sanctum::actingAs($otherTeacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ UNAUTHENTICATED
     */
    public function test_unauthenticated_cannot_reorder(): void
    {
        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(401);
    }

    /**
     * ❌ VALIDATION: Missing chapters array
     */
    public function test_missing_chapters_array(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", []);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.chapters'));
    }

    /**
     * ❌ VALIDATION: Empty chapters array
     */
    public function test_empty_chapters_array(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors.chapters'));
    }

    /**
     * ❌ VALIDATION: Missing chapter id
     */
    public function test_missing_chapter_id(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['order' => 0], // Missing id
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ VALIDATION: Invalid chapter id
     */
    public function test_invalid_chapter_id(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => 99999, 'order' => 0], // Doesn't exist
            ],
        ]);

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('errors'));
    }

    /**
     * ❌ VALIDATION: Missing order
     */
    public function test_missing_order(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id], // Missing order
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ VALIDATION: Negative order
     */
    public function test_negative_order(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/chapters/reorder", [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => -1],
            ],
        ]);

        $response->assertStatus(422);
    }

    /**
     * ❌ NOT FOUND: Non-existent lesson
     */
    public function test_nonexistent_lesson_returns_404(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons/99999/chapters/reorder', [
            'chapters' => [
                ['id' => $this->chapters[0]->id, 'order' => 0],
            ],
        ]);

        $response->assertStatus(404);
    }
}
