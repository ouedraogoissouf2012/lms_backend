<?php

declare(strict_types=1);

namespace Tests\Feature\Lesson;

use App\Models\Institution;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `LessonProgressController`
 * (axe #1 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON EXACTE des réponses afin qu'une migration vers
 * `successResponse`/`errorResponse` ne change RIEN côté client :
 *
 *   - 4 erreurs « Cours non trouvé » (404, identiques sur les 4 endpoints) ;
 *   - 4 succès migrables (getProgress étudiant, updateProgress, markComplete,
 *     rate) — enveloppe `{success[, message], data}` ;
 *   - 1 succès NON migrable (getProgress enseignant) qui expose une clé racine
 *     `statistics` hors enveloppe ; figé ici pour prouver qu'il reste inline.
 *
 * @see app/Http/Controllers/API/Lesson/LessonProgressController.php
 */
final class LessonProgressResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;
    private User $teacher;
    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $this->teacher->id,
            'status' => 'published',
        ]);
    }

    // ───────────────────────── getProgress ─────────────────────────

    public function test_get_progress_missing_lesson_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/lessons/999999/progress');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Cours non trouvé']);
    }

    public function test_get_progress_as_student_returns_success_with_data_only(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/lessons/{$this->lesson->id}/progress");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function test_get_progress_as_teacher_keeps_root_statistics_key(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson("/api/lessons/{$this->lesson->id}/progress");

        $response->assertStatus(200);
        $body = $response->json();
        // Forme NON migrable : `statistics` est une clé racine hors enveloppe que
        // `successResponse()` ne reproduit pas — doit rester inline après migration.
        $this->assertSame(['success', 'data', 'statistics'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertIsArray($body['data']);
        $this->assertSame(
            ['total_students', 'students_started', 'students_completed', 'average_completion_rate'],
            array_keys($body['statistics']),
        );
    }

    // ───────────────────────── updateProgress ─────────────────────────

    public function test_update_progress_missing_lesson_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/lessons/999999/progress', ['progress_percentage' => 50]);

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Cours non trouvé']);
    }

    public function test_update_progress_returns_success_with_message_and_data(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson(
            "/api/lessons/{$this->lesson->id}/progress",
            ['progress_percentage' => 50, 'time_spent_minutes' => 10],
        );

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Progression mise à jour', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── markComplete ─────────────────────────

    public function test_mark_complete_missing_lesson_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/lessons/999999/complete');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Cours non trouvé']);
    }

    public function test_mark_complete_returns_success_with_message_and_data(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/lessons/{$this->lesson->id}/complete");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Cours marqué comme complété', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── rate ─────────────────────────

    public function test_rate_missing_lesson_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/lessons/999999/rating', ['rating' => 4]);

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Cours non trouvé']);
    }

    public function test_rate_returns_success_with_message_and_data(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson(
            "/api/lessons/{$this->lesson->id}/rating",
            ['rating' => 4, 'feedback' => 'Très bien'],
        );

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Note enregistrée', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }
}
