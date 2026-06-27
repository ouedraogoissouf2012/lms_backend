<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\Evaluation\Student\EvaluationAttemptStateService;
use App\Services\KlassciProxyService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * `EvaluationStudentAttemptController` (axe #1, groupe-04 — test-first AVANT
 * migration vers `RespondsWithJson`).
 *
 * Verrouille la forme JSON exacte des 3 succès + 9 call-sites d'erreur afin
 * qu'une migration vers `successResponse`/`errorResponse` ne change RIEN côté
 * client. Les erreurs migrables sont assertées en `assertExactJson` ; les
 * succès via présence/absence des clés d'enveloppe.
 *
 * Deux réponses NE SONT PAS migrables (clés racine hors enveloppe que le trait
 * ne reproduit pas) et restent inline — leur forme actuelle est gelée ici pour
 * prouver qu'elles ne bougent pas :
 *   - arm `window_closed` : clé racine `window`.
 *   - arm `ok` (succès start) : clés racine `window` + `is_practice`.
 *
 * Non couverts (justifiés) :
 *   - arm `default` (500 « Erreur inconnue ») : défensif, injoignable —
 *     `EvaluationAttemptStateService::startAttempt` ne renvoie jamais de statut
 *     hors de l'ensemble connu ; migration 1:1 littérale.
 *
 * @see app/Http/Controllers/API/Evaluation/Student/EvaluationStudentAttemptController.php
 */
final class EvaluationStudentAttemptResponseTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->create([
            'institution_id' => $this->institution->id,
            'klassci_id' => 5555,
            'klassci_token' => 'student-klassci-token',
        ]);
    }

    /**
     * Évaluation publiée (non terminée → pas de mode entraînement) avec une
     * question, dans l'institution de l'étudiant.
     */
    private function publishedEvaluation(int $maxAttempts = 3): Evaluation
    {
        $evaluation = Evaluation::factory()->planifiee()->create([
            'institution_id' => $this->institution->id,
            'klassci_evaluation_id' => 9001,
            'max_attempts' => $maxAttempts,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'type' => 'qcm',
        ]);

        return $evaluation;
    }

    /**
     * Fige la fenêtre KLASSCI renvoyée par le proxy pour l'endpoint
     * `evaluations`. `null` ⇒ aucune fenêtre (le check passe).
     *
     * @param  ?array<string, mixed>  $window
     */
    private function fakeKlassciWindow(int $klassciEvaluationId, ?array $window): void
    {
        $payload = $window === null
            ? ['data' => []]
            : ['data' => [['id' => $klassciEvaluationId, 'programmation' => ['window' => $window]]]];

        $this->mock(KlassciProxyService::class, function (MockInterface $mock) use ($payload): void {
            $mock->shouldReceive('requestWithUserToken')->andReturn($payload);
        });
    }

    // ───────────────────────── startEvaluation ─────────────────────────

    public function test_start_unknown_evaluation_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson('/api/evaluations/999999/start');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Évaluation non disponible']);
    }

    public function test_start_without_questions_returns_422_error_envelope(): void
    {
        $evaluation = Evaluation::factory()->planifiee()->create([
            'institution_id' => $this->institution->id,
        ]);
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(422)
            ->assertExactJson([
                'success' => false,
                'message' => 'Cette évaluation n\'a pas encore de questions.',
            ]);
    }

    public function test_start_without_klassci_token_returns_401_error_envelope(): void
    {
        $evaluation = $this->publishedEvaluation();
        $this->student->klassci_token = null;
        $this->student->save();
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Token KLASSCI non trouvé. Veuillez vous reconnecter.',
            ]);
    }

    public function test_start_when_max_attempts_reached_returns_403_error_envelope(): void
    {
        $evaluation = $this->publishedEvaluation(maxAttempts: 1);
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'klassci_etudiant_id' => $this->student->klassci_id,
            'status' => 'soumis',
        ]);
        $this->fakeKlassciWindow($evaluation->klassci_evaluation_id, null);
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'Nombre maximum de tentatives atteint (1)',
            ]);
    }

    /**
     * Arm `window_closed` — NON migrable (clé racine `window`). On gèle la forme
     * inline exacte pour prouver qu'elle reste inchangée après migration.
     */
    public function test_start_when_window_closed_returns_403_inline_shape_with_window_key(): void
    {
        $evaluation = $this->publishedEvaluation();
        $window = [
            'is_open' => false,
            'has_started' => true,
            'has_ended' => true,
            'end_at' => '2020-01-01 10:00:00',
        ];
        $this->fakeKlassciWindow($evaluation->klassci_evaluation_id, $window);
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'L\'évaluation est fermée depuis le 01/01/2020 à 10:00',
                'window' => $window,
            ]);
    }

    /**
     * Arm `ok` (succès start) — NON migrable (clés racine `window` +
     * `is_practice`). On gèle la présence des clés d'enveloppe ET des clés hors
     * enveloppe pour prouver l'absence de régression après migration.
     */
    public function test_start_ok_returns_200_success_with_window_and_is_practice_keys(): void
    {
        $evaluation = $this->publishedEvaluation();
        $this->fakeKlassciWindow($evaluation->klassci_evaluation_id, null);
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/start");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Évaluation démarrée')
            ->assertJsonPath('window', null)
            ->assertJsonPath('is_practice', false)
            ->assertJsonStructure(['success', 'message', 'data', 'window', 'is_practice']);
    }

    // ───────────────────────── submitEvaluation ─────────────────────────

    public function test_submit_success_returns_201_with_message_and_data(): void
    {
        $evaluation = $this->publishedEvaluation();
        $question = $evaluation->questions()->first();
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => [['question_id' => $question->id, 'answer' => 'A']],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Évaluation soumise avec succès')
            ->assertJsonStructure(['success', 'message', 'data' => ['submission', 'score', 'note_sur_20']]);
        $this->assertArrayNotHasKey('errors', $response->json());
    }

    public function test_submit_failure_returns_500_error_envelope(): void
    {
        $evaluation = $this->publishedEvaluation();
        $question = $evaluation->questions()->first();
        $this->mock(
            EvaluationGradingService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('submit')->andThrow(new Exception('boom')),
        );
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => [['question_id' => $question->id, 'answer' => 'A']],
        ]);

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Erreur lors de la soumission']);
    }

    // ───────────────────────── getTimeStatus ─────────────────────────

    public function test_time_status_success_returns_200_data_only(): void
    {
        $evaluation = $this->publishedEvaluation();
        $this->fakeKlassciWindow($evaluation->klassci_evaluation_id, null);
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/evaluations/{$evaluation->id}/time-status");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'data' => ['window', 'server_time']]);
        $this->assertArrayNotHasKey('message', $response->json());
    }

    public function test_time_status_unknown_evaluation_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/evaluations/999999/time-status');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Évaluation non trouvée']);
    }

    public function test_time_status_without_klassci_token_returns_401_error_envelope(): void
    {
        $evaluation = $this->publishedEvaluation();
        $this->student->klassci_token = null;
        $this->student->save();
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/evaluations/{$evaluation->id}/time-status");

        $response->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Token KLASSCI non trouvé']);
    }

    public function test_time_status_unexpected_error_returns_500_error_envelope(): void
    {
        $evaluation = $this->publishedEvaluation();
        $this->mock(
            EvaluationAttemptStateService::class,
            fn (MockInterface $mock) => $mock->shouldReceive('getTimeStatus')->andThrow(new Exception('boom')),
        );
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/evaluations/{$evaluation->id}/time-status");

        $response->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'Impossible de récupérer l\'état temporel']);
    }
}
