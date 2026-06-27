<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Test de CARACTÉRISATION du contrat de réponse de
 * `EvaluationStudentSubmissionController` (axe #1, groupe-04 — test-first AVANT
 * migration vers `RespondsWithJson`).
 *
 * Gèle la forme JSON exacte du succès + des 4 call-sites d'erreur (sauf le 500
 * défensif, voir ci-dessous). Erreurs en `assertExactJson` ; succès via
 * présence/absence des clés d'enveloppe.
 *
 * Non couvert (justifié) :
 *   - `catch` 500 (« Erreur lors de la récupération de la soumission ») :
 *     handler défensif sans dépendance injectée (le controller manipule
 *     directement `Evaluation`/`EvaluationSubmission`), donc aucun seam pour
 *     forcer une exception sans hack fragile sur Eloquent. La migration est une
 *     substitution littérale 1:1 ({success,message}+500 identique).
 *
 * @see app/Http/Controllers/API/Evaluation/Student/EvaluationStudentSubmissionController.php
 */
final class EvaluationStudentSubmissionResponseTest extends TestCase
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

    private function publishedEvaluation(): Evaluation
    {
        $evaluation = Evaluation::factory()->planifiee()->create([
            'institution_id' => $this->institution->id,
        ]);
        EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'type' => 'qcm',
        ]);

        return $evaluation;
    }

    public function test_my_submission_without_klassci_id_returns_401_error_envelope(): void
    {
        $this->student->forceFill(['klassci_id' => null])->save();
        Sanctum::actingAs($this->student->fresh());

        $response = $this->getJson('/api/evaluations/1/my-submission');

        $response->assertStatus(401)
            ->assertExactJson([
                'success' => false,
                'message' => 'Utilisateur sans ID KLASSCI synchronisé',
            ]);
    }

    public function test_my_submission_unknown_evaluation_returns_404_error_envelope(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->getJson('/api/evaluations/999999/my-submission');

        $response->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Évaluation non trouvée']);
    }

    public function test_my_submission_without_submission_returns_404_error_envelope(): void
    {
        $evaluation = $this->publishedEvaluation();
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/evaluations/{$evaluation->id}/my-submission");

        $response->assertStatus(404)
            ->assertExactJson([
                'success' => false,
                'message' => 'Aucune soumission trouvée pour cette évaluation',
            ]);
    }

    public function test_my_submission_success_returns_200_data_only(): void
    {
        $evaluation = $this->publishedEvaluation();
        EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'klassci_etudiant_id' => $this->student->klassci_id,
            'status' => 'corrige',
            'submitted_at' => null,
        ]);
        Sanctum::actingAs($this->student);

        $response = $this->getJson("/api/evaluations/{$evaluation->id}/my-submission");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.correction_available', false)
            ->assertJsonPath('data.correction_delay_days', 7)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'evaluation_id',
                    'attempt',
                    'status',
                    'questions',
                    'answers',
                    'correction_available',
                    'correction_available_at',
                    'correction_delay_days',
                    'evaluation' => ['id', 'titre', 'bareme', 'coefficient'],
                ],
            ]);
        $this->assertArrayNotHasKey('message', $response->json());
    }
}
