<?php

declare(strict_types=1);

namespace Tests\Feature\Evaluation\Teacher;

use App\Models\Evaluation;
use App\Models\EvaluationQuestion;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #588 — notation manuelle des dissertations + déblocage du sync KLASSCI.
 */
final class EvaluationManualGradeTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();
        $this->institution = Institution::factory()->create();
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
            'klassci_enseignant_id' => 42,
            'last_klassci_sync' => now(),
        ]);
    }

    public function test_teacher_grades_dissertation_and_recomputes_note(): void
    {
        [$evaluation, $question, $submission] = $this->dissertationSubmission();

        Sanctum::actingAs($this->teacher);
        $response = $this->postJson("/api/evaluations/{$evaluation->id}/submissions/{$submission->id}/grade", [
            'points' => [$question->id => 8],
            'feedback' => 'Bien argumenté',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'corrige');
        self::assertEquals(16.0, (float) $response->json('data.note_sur_20'));

        $this->assertDatabaseHas('evaluation_submissions', [
            'id' => $submission->id,
            'status' => 'corrige',
            'graded_by' => $this->teacher->id,
        ]);
    }

    public function test_student_cannot_grade(): void
    {
        [$evaluation, $question, $submission] = $this->dissertationSubmission();
        $student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
            'last_klassci_sync' => now(),
        ]);

        Sanctum::actingAs($student);
        $this->postJson("/api/evaluations/{$evaluation->id}/submissions/{$submission->id}/grade", [
            'points' => [$question->id => 8],
        ])->assertStatus(403);
    }

    public function test_sync_stays_blocked_while_dissertation_is_ungraded(): void
    {
        [$evaluation] = $this->dissertationSubmission(klassciEvalId: 8888);
        config(['services.klassci.url' => 'https://klassci.test']);
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/evaluations/{$evaluation->id}/sync-klassci")
            ->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_sync_is_allowed_after_manual_grade(): void
    {
        [$evaluation, $question, $submission] = $this->dissertationSubmission(klassciEvalId: 8888);
        config(['services.klassci.url' => 'https://klassci.test']);
        Http::fake(['*' => Http::response(['success' => true, 'saved' => 1], 200)]);

        Sanctum::actingAs($this->teacher);
        $this->postJson("/api/evaluations/{$evaluation->id}/submissions/{$submission->id}/grade", [
            'points' => [$question->id => 10],
        ])->assertOk();

        $this->postJson("/api/evaluations/{$evaluation->id}/sync-klassci")
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    /**
     * @return array{0: Evaluation, 1: EvaluationQuestion, 2: EvaluationSubmission}
     */
    private function dissertationSubmission(?int $klassciEvalId = null): array
    {
        $evaluation = Evaluation::factory()->create([
            'institution_id' => $this->institution->id,
            'klassci_enseignant_id' => 42,
            'klassci_evaluation_id' => $klassciEvalId,
            'bareme' => 20,
        ]);
        $question = EvaluationQuestion::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'type' => 'dissertation',
            'points' => 10,
        ]);
        $submission = EvaluationSubmission::factory()->create([
            'evaluation_id' => $evaluation->id,
            'institution_id' => $this->institution->id,
            'status' => 'soumis',
            'answers' => [$question->id => 'Une dissertation'],
            'note_sur_20' => 0,
            'score' => 0,
        ]);

        return [$evaluation, $question, $submission];
    }
}
