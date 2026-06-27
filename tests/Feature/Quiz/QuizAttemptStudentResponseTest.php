<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * `QuizAttemptStudentController` (axe #1, groupe-08 — test-first).
 *
 * Périmètre de migration (cf. groupe-08 : « 1 err, 1 RACINE — MIXTE ») :
 *   - `notFound()` (404) — `'success' => false` LITTÉRAL → migrée vers
 *     `errorResponse()`. Atteinte ici via `POST /submit` sur un id inexistant
 *     (la `SubmitQuizAttemptRequest::authorize` = `auth()->check()` laisse
 *     passer, puis le controller renvoie 404).
 *
 * Hors migration (verrouillé ici pour prouver la non-régression du contrat) :
 *   - `showAttempt()` — RACINE : `success` DYNAMIQUE (`$result['success']`),
 *     status variable, `data` placé directement (pas de tableau d'enveloppe).
 *     Reste inline. Ce test fige sa forme actuelle `{success, data}`.
 *
 * Le dispatcher privé `toJson()` (success dynamique) reste lui aussi inline.
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptStudentController.php
 */
final class QuizAttemptStudentResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
    }

    // ───────────────────── notFound (migrée) ─────────────────────

    public function test_submit_on_missing_attempt_returns_exact_404_error_envelope(): void
    {
        $student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
        Sanctum::actingAs($student);

        $response = $this->postJson('/api/quiz-attempts/99999/submit', [
            'answers' => ['1' => 1],
        ]);

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Tentative non trouvée']);
    }

    // ───────────────────── showAttempt (RACINE, inline) ─────────────────────

    public function test_show_attempt_keeps_root_shape_success_and_data(): void
    {
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        $quiz = Quiz::factory()->create([
            'institution_id' => $this->institution->id,
            'created_by' => $teacher->id,
        ]);
        $attempt = QuizAttempt::factory()->inProgress()->create([
            'quiz_id' => $quiz->id,
            'user_id' => $student->id,
            'institution_id' => $this->institution->id,
        ]);

        Sanctum::actingAs($student);

        $response = $this->getJson("/api/quiz-attempts/{$attempt->id}");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertSame(['success', 'data'], array_keys($body));
        $this->assertTrue($body['success']);
        $this->assertArrayNotHasKey('message', $body);
    }
}
