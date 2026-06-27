<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Http\Controllers\API\Quiz\QuizAttemptTeacherController;
use App\Http\Requests\GradeAttemptRequest;
use App\Models\Institution;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * `QuizAttemptTeacherController` (axe #1, groupe-08 — test-first).
 *
 * Périmètre de migration (cf. groupe-08 : « 1 succ, 1 err ») :
 *   - `getAttempts()` (200) — `'success' => true` LITTÉRAL → `successResponse()`.
 *   - le 404 « Tentative non trouvée » de `gradeAttempt()` — `'success' => false`
 *     LITTÉRAL → `errorResponse()`.
 *
 * NOTE défense-en-profondeur : via le routing HTTP, le 404 de `gradeAttempt`
 * est INATTEIGNABLE — `GradeAttemptRequest::authorize()` appelle `abort(404,
 * 'Tentative non trouvée')` AVANT que le controller ne s'exécute (forme
 * `{message}` du handler Laravel, sans clé `success`). La ligne du controller
 * reste néanmoins une garde valable ; on la caractérise par invocation directe
 * du controller (en court-circuitant la FormRequest) pour figer sa forme exacte
 * `{success:false, message}` / 404 et garantir une migration 1:1.
 *
 * La réponse finale de `gradeAttempt` (succès dynamique `$result['success']`)
 * reste inline et est déjà couverte par `GradeAttemptHappyPathTest`.
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptTeacherController.php
 */
final class QuizAttemptTeacherResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
    }

    // ───────────────────── getAttempts (migrée) ─────────────────────

    public function test_get_attempts_returns_success_envelope_without_message(): void
    {
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $quiz = Quiz::factory()->create([
            'institution_id' => $this->institution->id,
            'created_by' => $teacher->id,
        ]);

        Sanctum::actingAs($teacher);

        $response = $this->getJson("/api/quizzes/{$quiz->id}/attempts");

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertTrue($body['success']);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayNotHasKey('message', $body);
    }

    // ───────────────────── grade 404 (migrée, défense en profondeur) ─────────────────────

    public function test_grade_missing_attempt_controller_guard_returns_exact_404_envelope(): void
    {
        $controller = app(QuizAttemptTeacherController::class);

        // Invocation directe : la garde `if ($attempt === null)` n'utilise PAS
        // la FormRequest, donc une instance vide suffit pour atteindre la ligne.
        $response = $controller->gradeAttempt(new GradeAttemptRequest(), 99999);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'message' => 'Tentative non trouvée'],
            $response->getData(true),
        );
    }
}
