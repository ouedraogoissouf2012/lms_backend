<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * `KnowledgeCheckAttemptController` (axe #1, groupe-08 — test-first).
 *
 * Verrouille la forme JSON EXACTE des 4 réponses (3 succès + 1 erreur) avant
 * leur migration vers `successResponse()` / `errorResponse()` :
 *   - startAttempt (quota dépassé) : `{success:false, message}` 400  (erreur)
 *   - startAttempt (succès)        : `{success:true, data}`           (succès)
 *   - submitAttempt                : `{success:true, message, data}`  (succès)
 *   - myAttempts                   : `{success:true, data}`           (succès)
 *
 * @see app/Http/Controllers/API/KnowledgeCheckAttemptController.php
 */
final class KnowledgeCheckAttemptResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        $lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'status' => 'published',
        ]);
        $this->chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);
    }

    private function quiz(int $maxAttempts = 3): KnowledgeCheck
    {
        return KnowledgeCheck::factory()->create([
            'chapter_id' => $this->chapter->id,
            'institution_id' => $this->institution->id,
            'is_active' => true,
            'max_attempts' => $maxAttempts,
        ]);
    }

    // ───────────────────── startAttempt : quota (erreur) ─────────────────────

    public function test_start_attempt_over_quota_returns_exact_400_error_envelope(): void
    {
        $quiz = $this->quiz(maxAttempts: 1);

        // Une tentative déjà consommée → quota atteint (max_attempts = 1).
        KnowledgeCheckAttempt::create([
            'knowledge_check_id' => $quiz->id,
            'user_id' => $this->student->id,
            'institution_id' => $this->institution->id,
            'score' => 0,
            'correct_answers' => 0,
            'total_questions' => 2,
            'answers' => [],
            'time_spent_seconds' => 30,
            'passed' => false,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/knowledge-checks/{$quiz->id}/start");

        $response->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'Nombre maximum de tentatives atteint',
            ]);
    }

    // ───────────────────── startAttempt : succès ─────────────────────

    public function test_start_attempt_returns_success_envelope_without_message(): void
    {
        $quiz = $this->quiz();
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/knowledge-checks/{$quiz->id}/start");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────── submitAttempt : succès ─────────────────────

    public function test_submit_attempt_returns_success_with_message_and_data(): void
    {
        $quiz = $this->quiz();
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/knowledge-checks/{$quiz->id}/submit", [
            'answers' => ['0' => '4', '1' => 'True'],
            'time_spent_seconds' => 120,
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertNotSame('', $body['message']);
        $this->assertArrayHasKey('attempt_id', $body['data']);
    }

    // ───────────────────── myAttempts : succès ─────────────────────

    public function test_my_attempts_returns_success_envelope_without_message(): void
    {
        $quiz = $this->quiz();
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/knowledge-checks/{$quiz->id}/my-attempts");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertArrayNotHasKey('message', $body);
    }
}
