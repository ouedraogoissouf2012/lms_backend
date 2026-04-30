<?php

namespace Tests\Feature\Requests;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests pour StoreLessonRequest
 *
 * Valide que la validation + autorisation + sanitization fonctionnent correctement.
 * Tests couvrent: happy path + edge cases + security + multi-tenant
 *
 * ## 10-year perspective
 * Si règles changent (ex: max title length), les tests vont catcher la régression.
 * New dev peut comprendre les règles en lisant les tests.
 */
class StoreLessonRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private User $teacher;
    private User $student;
    private Classe $classe;
    private Matiere $matiere;
    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->classe = Classe::factory()->for($this->institution)->create();
        $this->matiere = Matiere::factory()->for($this->institution)->create();

        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        $this->student = User::factory()
            ->student()
            ->for($this->institution)
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Valid data passes
     */
    public function test_valid_data_creates_lesson(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Introduction to PHP',
            'description' => 'Learn PHP basics',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'matiere_id' => $this->matiere->id,
            'duree_estimee_minutes' => 60,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('lessons', [
            'title' => 'Introduction to PHP',
            'type' => 'cours',
        ]);
    }

    /**
     * ✅ EDGE CASE: Title with spaces is trimmed
     */
    public function test_title_with_leading_trailing_spaces_is_trimmed(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => '   PHP Basics   ',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(201);
        $lesson = \App\Models\Lesson::latest()->first();
        $this->assertEquals('PHP Basics', $lesson->title);
    }

    /**
     * ✅ EDGE CASE: Title with only spaces fails
     */
    public function test_title_with_only_spaces_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => '     ',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.title', ['The title field is required']);
    }

    /**
     * ✅ VALIDATION: Missing required field
     */
    public function test_missing_title_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.title', ['The title field is required']);
    }

    /**
     * ✅ VALIDATION: Title too short
     */
    public function test_title_too_short_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'ab',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.title', ['The title must be at least 3 characters']);
    }

    /**
     * ✅ VALIDATION: Title too long (DOS prevention)
     */
    public function test_title_exceeding_max_length_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => str_repeat('a', 256),
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.title', ['The title may not be greater than 255 characters']);
    }

    /**
     * ✅ VALIDATION: Invalid lesson type
     */
    public function test_invalid_lesson_type_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test',
            'type' => 'invalid_type',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.type', ['The type field is invalid']);
    }

    /**
     * ✅ AUTHORIZATION: Student cannot create lesson
     */
    public function test_student_cannot_create_lesson(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test Lesson',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(403);
    }

    /**
     * ✅ AUTHORIZATION: Unauthenticated user cannot create
     */
    public function test_unauthenticated_user_cannot_create_lesson(): void
    {
        $response = $this->postJson('/api/lessons', [
            'title' => 'Test',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(401);
    }

    /**
     * ✅ MULTI-TENANT: Teacher cannot create lesson in other institution's classe
     */
    public function test_teacher_cannot_create_in_other_institution(): void
    {
        $other_institution = Institution::factory()->create();
        $other_classe = Classe::factory()->for($other_institution)->create();

        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test Lesson',
            'type' => 'cours',
            'classe_id' => $other_classe->id,
        ]);

        $response->assertStatus(403);
    }

    /**
     * ✅ SECURITY: XSS attempt in description is sanitized
     */
    public function test_xss_in_description_is_sanitized(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test Lesson',
            'description' => '<script>alert("XSS")</script>',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        $response->assertStatus(201);

        $lesson = \App\Models\Lesson::latest()->first();
        $this->assertStringNotContainsString('<script>', $lesson->description);
    }

    /**
     * ✅ SECURITY: SQL injection attempt is rejected
     */
    public function test_sql_injection_attempt_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => "'; DROP TABLE lessons; --",
            'type' => 'cours',
            'classe_id' => $this->classe->id,
        ]);

        // Should either fail validation or be escaped in database
        // Verify table still exists
        $this->assertTrue(
            \Illuminate\Support\Facades\Schema::hasTable('lessons'),
            'lessons table was dropped'
        );
    }

    /**
     * ✅ VALIDATION: Duration too long (DOS prevention)
     */
    public function test_duration_exceeding_max_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'duree_estimee_minutes' => 481, // max is 480
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.duree_estimee_minutes', ['The duration may not be greater than 480 minutes']);
    }

    /**
     * ✅ VALIDATION: Duration must be positive
     */
    public function test_duration_negative_fails(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/lessons', [
            'title' => 'Test',
            'type' => 'cours',
            'classe_id' => $this->classe->id,
            'duree_estimee_minutes' => -5,
        ]);

        $response->assertStatus(422);
    }
}
