<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Services\Evaluation\EvaluationScoreRecomputationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Remédiation #564 : recalcul des scores d'évaluation faussés à 0 par le bug de
 * format (soumissions historiques stockées en LISTE `[{question_id, answer}]`).
 *
 * Garanties vérifiées :
 *  - dry-run (défaut) : rapporte les changements SANS écrire en base ;
 *  - --apply : persiste le score recalculé ET normalise les réponses en MAP ;
 *  - fail-closed : une évaluation à correction manuelle (dissertation) est SKIPPÉE
 *    (sa note ne peut pas être finalisée automatiquement → jamais re-poussée, #588) ;
 *  - idempotence : une soumission déjà correcte reste « unchanged ».
 */
final class RecomputeEvaluationScoresTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    protected function setUp(): void
    {
        parent::setUp();
        $this->institution = Institution::factory()->create();
    }

    private function service(): EvaluationScoreRecomputationService
    {
        return app(EvaluationScoreRecomputationService::class);
    }

    /** Évaluation bareme 20 avec 2 qcm (correct A / correct B), 10 points chacun. */
    private function evaluationWithTwoQcm(): array
    {
        $evaluation = Evaluation::factory()->for($this->institution)->state([
            'bareme' => 20,
        ])->create();
        $q1 = EvaluationQuestion::factory()->for($evaluation)->state([
            'type' => 'qcm', 'correct_answers' => ['A'], 'points' => 10, 'ordre' => 1,
        ])->create();
        $q2 = EvaluationQuestion::factory()->for($evaluation)->state([
            'type' => 'qcm', 'correct_answers' => ['B'], 'points' => 10, 'ordre' => 2,
        ])->create();

        return [$evaluation, $q1, $q2];
    }

    /**
     * Soumission historique buggée : réponses correctes stockées en LISTE, mais
     * notées 0 (le bug de format).
     */
    private function buggedListSubmission(Evaluation $evaluation, EvaluationQuestion $q1, EvaluationQuestion $q2): EvaluationSubmission
    {
        return EvaluationSubmission::factory()->for($evaluation)->state([
            'institution_id' => $this->institution->id,
            'status' => 'soumis',
            'answers' => [
                ['question_id' => $q1->id, 'answer' => 'A'],
                ['question_id' => $q2->id, 'answer' => 'B'],
            ],
            'score' => 0,
            'note_sur_20' => 0,
        ])->create();
    }

    public function test_dry_run_reports_change_without_persisting(): void
    {
        [$evaluation, $q1, $q2] = $this->evaluationWithTwoQcm();
        $submission = $this->buggedListSubmission($evaluation, $q1, $q2);

        $results = $this->service()->recompute(apply: false);

        $this->assertCount(1, $results);
        $this->assertSame('changed', $results[0]['outcome']);
        $this->assertSame(0.0, $results[0]['old_note']);
        $this->assertSame(20.0, $results[0]['new_note']);

        // Dry-run : la base ne doit PAS bouger.
        $submission->refresh();
        $this->assertEquals(0.0, (float) $submission->note_sur_20, 'Dry-run ne persiste rien.');
    }

    public function test_apply_persists_corrected_score_and_normalized_answers(): void
    {
        [$evaluation, $q1, $q2] = $this->evaluationWithTwoQcm();
        $submission = $this->buggedListSubmission($evaluation, $q1, $q2);

        $results = $this->service()->recompute(apply: true);

        $this->assertSame('changed', $results[0]['outcome']);
        $submission->refresh();
        $this->assertEquals(20.0, (float) $submission->score, 'Score recalculé persisté.');
        $this->assertEquals(20.0, (float) $submission->note_sur_20, 'Note recalculée persistée.');
        // Réponses normalisées en MAP {question_id: réponse}.
        $this->assertSame('A', $submission->answers[$q1->id]);
        $this->assertSame('B', $submission->answers[$q2->id]);
    }

    public function test_skips_manual_grading_evaluation(): void
    {
        $evaluation = Evaluation::factory()->for($this->institution)->state(['bareme' => 20])->create();
        EvaluationQuestion::factory()->for($evaluation)->state([
            'type' => 'dissertation', 'correct_answers' => [], 'points' => 20, 'ordre' => 1,
        ])->create();
        $submission = EvaluationSubmission::factory()->for($evaluation)->state([
            'institution_id' => $this->institution->id,
            'status' => 'soumis',
            'answers' => [['question_id' => 1, 'answer' => 'Une longue dissertation.']],
            'score' => 0,
            'note_sur_20' => 0,
        ])->create();

        $results = $this->service()->recompute(apply: true);

        $this->assertSame('skipped_manual', $results[0]['outcome']);
        $submission->refresh();
        $this->assertEquals(0.0, (float) $submission->note_sur_20, 'Éval à correction manuelle non modifiée.');
    }

    public function test_leaves_already_correct_submission_unchanged(): void
    {
        [$evaluation, $q1, $q2] = $this->evaluationWithTwoQcm();
        // Déjà correcte : map + note 20.
        EvaluationSubmission::factory()->for($evaluation)->state([
            'institution_id' => $this->institution->id,
            'status' => 'soumis',
            'answers' => [$q1->id => 'A', $q2->id => 'B'],
            'score' => 20,
            'note_sur_20' => 20,
        ])->create();

        $results = $this->service()->recompute(apply: true);

        $this->assertSame('unchanged', $results[0]['outcome']);
    }

    public function test_command_runs_in_dry_run_and_reports(): void
    {
        [$evaluation, $q1, $q2] = $this->evaluationWithTwoQcm();
        $submission = $this->buggedListSubmission($evaluation, $q1, $q2);

        $this->artisan('evaluations:recompute-scores')
            ->assertExitCode(0);

        // Sans --apply, aucune écriture.
        $submission->refresh();
        $this->assertEquals(0.0, (float) $submission->note_sur_20);
    }
}
