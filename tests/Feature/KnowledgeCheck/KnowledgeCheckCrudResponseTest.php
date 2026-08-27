<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de `KnowledgeCheckCrudController`
 * (axe #1, PR-3 — R7.1 : test-first AVANT migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON exacte des 11 réponses (6 succès + 5 erreurs) afin
 * qu'une migration vers `successResponse`/`errorResponse` ne change RIEN côté
 * client. Les erreurs sont assertées en `assertExactJson` ; les succès via
 * présence/absence de clés d'enveloppe (`message` omis sur index/show/getByChapter,
 * `data` omis sur destroy).
 *
 * @see app/Http/Controllers/API/KnowledgeCheckCrudController.php
 */
final class KnowledgeCheckCrudResponseTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $otherTeacher;
    private Chapter $chapter;
    private Chapter $emptyChapter;
    private KnowledgeCheck $quiz;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $institution = Institution::factory()->create();
        $this->owner = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
        ]);
        $this->otherTeacher = User::factory()->create([
            'institution_id' => $institution->id,
            'role' => 'enseignant',
        ]);

        $lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'enseignant_id' => $this->owner->id,
            'status' => 'published',
        ]);
        $this->chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $this->owner->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);
        $this->emptyChapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $this->owner->id,
            'content_type' => 'quiz',
            'order' => 1,
        ]);
        $this->quiz = KnowledgeCheck::factory()->create([
            'chapter_id' => $this->chapter->id,
            'institution_id' => $institution->id,
            'is_active' => true,
        ]);
    }

    /**
     * @return array<string, mixed>  Payload de création valide (passe la FormRequest).
     */
    private function validStorePayload(int $chapterId): array
    {
        return [
            'chapter_id' => $chapterId,
            'title' => 'Quiz de caractérisation',
            'questions' => [
                [
                    'question' => 'Capitale ?',
                    'type' => 'single',
                    'options' => ['Ouaga', 'Bobo'],
                    'correct_answer' => 'Ouaga',
                ],
            ],
        ];
    }

    // ───────────────────────── index ─────────────────────────

    public function test_index_without_chapter_id_returns_422_error_envelope(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/knowledge-checks');

        $response->assertStatus(422)
            ->assertExactJson(['success' => false, 'message' => 'chapter_id requis']);
    }

    public function test_index_with_chapter_id_returns_success_without_message(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/knowledge-checks?chapter_id={$this->chapter->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── store ─────────────────────────

    public function test_store_as_owner_returns_201_with_message_and_data(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/knowledge-checks', $this->validStorePayload($this->chapter->id));

        $response->assertStatus(201);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Quiz cree avec succes', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function test_store_as_non_owner_returns_403_error_envelope(): void
    {
        Sanctum::actingAs($this->otherTeacher);

        $response = $this->postJson('/api/knowledge-checks', $this->validStorePayload($this->chapter->id));

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Non autorise a modifier ce chapitre']);
    }

    // ───────────────────────── getByChapter ─────────────────────────

    public function test_get_by_chapter_without_active_quiz_returns_404(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/knowledge-checks/chapter/{$this->emptyChapter->id}");

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Aucun quiz actif pour ce chapitre']);
    }

    public function test_get_by_chapter_with_active_quiz_returns_success_without_message(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/knowledge-checks/chapter/{$this->chapter->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── show ─────────────────────────

    public function test_show_returns_success_without_message(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson("/api/knowledge-checks/{$this->quiz->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────────── update ─────────────────────────

    public function test_update_as_owner_returns_200_with_message_and_data(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->putJson("/api/knowledge-checks/{$this->quiz->id}", ['title' => 'Titre mis à jour']);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Quiz mis a jour', $body['message']);
        $this->assertArrayHasKey('id', $body['data']);
    }

    public function test_update_as_non_owner_returns_403_error_envelope(): void
    {
        Sanctum::actingAs($this->otherTeacher);

        $response = $this->putJson("/api/knowledge-checks/{$this->quiz->id}", ['title' => 'Hack']);

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Non autorise a modifier ce quiz']);
    }

    // ───────────────────────── destroy ─────────────────────────

    public function test_destroy_as_owner_returns_200_with_message_without_data(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->deleteJson("/api/knowledge-checks/{$this->quiz->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'message'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertSame('Quiz supprime', $body['message']);
        $this->assertArrayNotHasKey('data', $body);
    }

    public function test_destroy_as_non_owner_returns_403_error_envelope(): void
    {
        Sanctum::actingAs($this->otherTeacher);

        $response = $this->deleteJson("/api/knowledge-checks/{$this->quiz->id}");

        $response->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Non autorise a supprimer ce quiz']);
    }
}
