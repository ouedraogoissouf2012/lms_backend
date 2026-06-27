<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `QuizCrudController`
 * (axe #1, groupe-08 — test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON EXACTE des 4 réponses-enveloppe à succès littéral
 * (`'success' => true`) qui seront migrées vers `successResponse()` :
 *   - index   : `{success, data}`            (message OMIS)
 *   - store   : `{success, message, data}`   201
 *   - update  : `{success, message, data}`   200
 *   - destroy : `{success, message}`         (data OMIS)
 *
 * Les réponses `show()` et `publish()` ne sont PAS couvertes ici : leur clé
 * `success` est DYNAMIQUE (`$result['success']`, donc potentiellement `false`)
 * et leur status est variable — elles ne mappent pas 1:1 sur une fabrique du
 * trait et restent inline (cf. MIXTE / `LessonCrudController::myCourses`).
 *
 * @see app/Http/Controllers/API/Quiz/QuizCrudController.php
 */
final class QuizCrudResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->teacher = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
        ]);
    }

    private function ownedQuiz(): Quiz
    {
        return Quiz::factory()->create([
            'institution_id' => $this->teacher->institution_id,
            'created_by' => $this->teacher->id,
        ]);
    }

    // ───────────────────────── index ─────────────────────────

    public function test_index_returns_success_envelope_without_message(): void
    {
        $this->ownedQuiz();
        Sanctum::actingAs($this->teacher);

        $response = $this->getJson('/api/quizzes');

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── store ─────────────────────────

    public function test_store_returns_201_with_message_and_data(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson('/api/quizzes', ['title' => 'Quiz de caractérisation']);

        $response->assertStatus(201);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Quiz créé avec succès', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── update ─────────────────────────

    public function test_update_returns_200_with_message_and_data(): void
    {
        $quiz = $this->ownedQuiz();
        Sanctum::actingAs($this->teacher);

        $response = $this->putJson("/api/quizzes/{$quiz->id}", ['title' => 'Titre mis à jour']);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Quiz mis à jour', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    // ───────────────────────── destroy ─────────────────────────

    public function test_destroy_returns_200_with_message_without_data(): void
    {
        $quiz = $this->ownedQuiz();
        Sanctum::actingAs($this->teacher);

        $response = $this->deleteJson("/api/quizzes/{$quiz->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Quiz supprimé', $body['message']);
        $this->assertArrayNotHasKey('data', $body);
    }
}
