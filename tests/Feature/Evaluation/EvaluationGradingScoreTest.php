<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Preuve de VALEUR de la notation des évaluations (#564).
 *
 * Le frontend, le service de correction et le quiz utilisent une MAP
 * `{ question_id: réponse }`. Ces tests verrouillent ce contrat côté API :
 *   - une soumission map avec réponses correctes produit un score RÉEL (≠ 0) ;
 *   - le format LISTE obsolète `[{question_id, answer}]` est rejeté en 422
 *     (jamais un 0 silencieux, jamais un 500) ;
 *   - les valeurs vides (question non répondue) sont tolérées (0 point légitime).
 *
 * @see app/Http/Requests/SubmitEvaluationRequest.php
 * @see app/Services/Evaluation/EvaluationGradingService.php
 */
final class EvaluationGradingScoreTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->for($this->institution)->create([
            'klassci_id' => 1234,
        ]);
    }

    /** Évaluation publiée, échéance future, bareme 20, dans l'institution du test. */
    private function publishedEvaluation(?Institution $institution = null): Evaluation
    {
        return Evaluation::factory()->for($institution ?? $this->institution)->state([
            'status' => 'published',
            'is_published' => true,
            'bareme' => 20,
            'deadline_at' => now()->addDays(7),
        ])->create();
    }

    private function question(Evaluation $evaluation, string $type, array $correct, int $points, int $ordre): EvaluationQuestion
    {
        return EvaluationQuestion::factory()->for($evaluation)->state([
            'type' => $type,
            'correct_answers' => $correct,
            'points' => $points,
            'ordre' => $ordre,
        ])->create();
    }

    /**
     * @param  array<int|string, mixed>  $answers
     */
    private function submit(Evaluation $evaluation, array $answers)
    {
        return $this->postJson("/api/evaluations/{$evaluation->id}/submit", [
            'answers' => $answers,
        ]);
    }

    private function latestSubmission(Evaluation $evaluation): ?EvaluationSubmission
    {
        return EvaluationSubmission::where('evaluation_id', $evaluation->id)->first();
    }

    // ───────────────────────── GREEN — score réel ─────────────────────────

    public function test_map_payload_all_correct_scores_full(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q1 = $this->question($evaluation, 'qcm', ['A'], 10, 1);
        $q2 = $this->question($evaluation, 'qcm', ['B'], 10, 2);
        Sanctum::actingAs($this->student);

        $response = $this->submit($evaluation, [$q1->id => 'A', $q2->id => 'B']);

        $response->assertStatus(201);
        $submission = $this->latestSubmission($evaluation);
        $this->assertEquals(20.0, (float) $submission->score, 'Deux bonnes réponses = 20 points bruts.');
        $this->assertEquals(20.0, (float) $submission->note_sur_20, 'Score plein → note = bareme (20/20).');
    }

    public function test_map_payload_partial_correct_scores_partial(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q1 = $this->question($evaluation, 'qcm', ['A'], 10, 1);
        $q2 = $this->question($evaluation, 'qcm', ['B'], 10, 2);
        Sanctum::actingAs($this->student);

        $response = $this->submit($evaluation, [$q1->id => 'A', $q2->id => 'MAUVAISE']);

        $response->assertStatus(201);
        $submission = $this->latestSubmission($evaluation);
        $this->assertEquals(10.0, (float) $submission->score, 'Une bonne / une fausse = 10 points bruts.');
        $this->assertEquals(10.0, (float) $submission->note_sur_20, '10/20 points → note 10.');
    }

    public function test_map_payload_qcm_multiple_exact_set_scores(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q = $this->question($evaluation, 'qcm_multiple', ['A', 'C'], 10, 1);
        Sanctum::actingAs($this->student);

        // Ordre différent volontairement : le service trie avant comparaison.
        $response = $this->submit($evaluation, [$q->id => ['C', 'A']]);

        $response->assertStatus(201);
        $submission = $this->latestSubmission($evaluation);
        $this->assertEquals(10.0, (float) $submission->score, 'Ensemble exact de réponses = points crédités.');
        $this->assertEquals(20.0, (float) $submission->note_sur_20, '10/10 points → note = bareme.');
    }

    public function test_map_payload_empty_values_score_zero_without_error(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q1 = $this->question($evaluation, 'qcm', ['A'], 10, 1);
        $q2 = $this->question($evaluation, 'qcm_multiple', ['A', 'B'], 10, 2);
        Sanctum::actingAs($this->student);

        // Question non répondue : '' (scalaire) et [] (qcm_multiple) — contrat frontend réel.
        $response = $this->submit($evaluation, [$q1->id => '', $q2->id => []]);

        $response->assertStatus(201);
        $submission = $this->latestSubmission($evaluation);
        $this->assertEquals(0.0, (float) $submission->score, 'Rien répondu = 0 (légitime, pas un bug).');
        $this->assertEquals(0.0, (float) $submission->note_sur_20);
    }

    // ───────────────────── Rejets propres (422, jamais 500 ni 0 silencieux) ─────────────────────

    public function test_obsolete_list_payload_is_rejected_with_422(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q1 = $this->question($evaluation, 'qcm', ['A'], 10, 1);
        Sanctum::actingAs($this->student);

        // Ancien format LISTE : doit être rejeté proprement, pas noté 0 en silence.
        $response = $this->submit($evaluation, [['question_id' => $q1->id, 'answer' => 'A']]);

        $response->assertStatus(422);
        $this->assertNull(
            $this->latestSubmission($evaluation),
            'Un payload liste invalide ne doit créer aucune soumission.'
        );
    }

    public function test_answer_exceeding_max_length_is_rejected_with_422(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q1 = $this->question($evaluation, 'reponse_courte', ['ok'], 10, 1);
        Sanctum::actingAs($this->student);

        $response = $this->submit($evaluation, [$q1->id => str_repeat('a', 10001)]);

        $response->assertStatus(422);
    }

    public function test_boolean_answer_value_is_rejected_with_422(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q1 = $this->question($evaluation, 'qcm', ['A'], 10, 1);
        Sanctum::actingAs($this->student);

        // Ferme le type-juggling (cohérent #498 côté quiz) : un booléen n'est pas une réponse.
        $response = $this->submit($evaluation, [$q1->id => true]);

        $response->assertStatus(422);
    }

    public function test_empty_answers_map_is_rejected_with_422(): void
    {
        $evaluation = $this->publishedEvaluation();
        $this->question($evaluation, 'qcm', ['A'], 10, 1);
        Sanctum::actingAs($this->student);

        $response = $this->submit($evaluation, []);

        $response->assertStatus(422);
    }

    /**
     * Robustesse (#564 MEDIUM) : un tableau envoyé pour une question scalaire
     * (payload mal formé, ex. reponse_courte) ne doit JAMAIS provoquer un 500
     * (« Array to string conversion ») — il est noté 0 proprement.
     */
    public function test_array_answer_for_scalar_type_does_not_error(): void
    {
        $evaluation = $this->publishedEvaluation();
        $q = $this->question($evaluation, 'reponse_courte', ['paris'], 10, 1);
        Sanctum::actingAs($this->student);

        $response = $this->submit($evaluation, [$q->id => ['paris', 'lyon']]);

        $response->assertStatus(201);
        $submission = $this->latestSubmission($evaluation);
        $this->assertEquals(0.0, (float) $submission->score, 'Tableau invalide pour un type scalaire = 0, sans 500.');
    }

    // ───────────────────────── Multi-tenant (§1.3) ─────────────────────────

    public function test_two_institutions_grade_independently(): void
    {
        // Institution A
        $evalA = $this->publishedEvaluation();
        $qA = $this->question($evalA, 'qcm', ['A'], 10, 1);
        Sanctum::actingAs($this->student);
        $responseA = $this->submit($evalA, [$qA->id => 'A']);
        $responseA->assertStatus(201);
        $this->assertEquals(20.0, (float) $this->latestSubmission($evalA)->note_sur_20);

        // Institution B — étudiant et évaluation distincts
        $institutionB = Institution::factory()->create();
        $studentB = User::factory()->student()->for($institutionB)->create(['klassci_id' => 5678]);
        $evalB = $this->publishedEvaluation($institutionB);
        $qB = $this->question($evalB, 'qcm', ['X'], 10, 1);
        Sanctum::actingAs($studentB);
        $responseB = $this->submit($evalB, [$qB->id => 'X']);
        $responseB->assertStatus(201);
        $this->assertEquals(20.0, (float) $this->latestSubmission($evalB)->note_sur_20);

        // Indépendance : chaque évaluation n'a qu'une soumission, notée sur ses propres questions.
        $this->assertEquals(1, EvaluationSubmission::where('evaluation_id', $evalA->id)->count());
        $this->assertEquals(1, EvaluationSubmission::where('evaluation_id', $evalB->id)->count());
    }
}
