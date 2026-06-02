<?php

declare(strict_types=1);

namespace Tests\Feature\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integration tests for `POST /api/quiz-attempts/{id}/submit`.
 *
 * Comble le gap test-coverage identifié dans l'audit total post-TIER 2 :
 * `QuizAttemptStudentController::submitAttempt` mute les notes étudiants
 * **sans aucune couverture test feature** (seul la FormRequest était testée).
 *
 * Tests :
 *  - Happy path : étudiant soumet ses réponses → score auto-calculé, status `graded`
 *  - 403 : non-owner tente de soumettre l'attempt d'un autre étudiant
 *  - 422 : tentative déjà soumise ne peut pas être resoumise
 *  - 404 : attempt inexistant
 *  - cross-tenant : attaquant d'une autre institution ne peut pas soumettre
 *
 * @see app/Http/Controllers/API/Quiz/QuizAttemptStudentController.php::submitAttempt
 * @see app/Services/Quiz/QuizAttemptLifecycleService.php (split-10/quiz-attempt)
 * @see app/Services/Quiz/QuizGradingService.php (PERF-04 batch 1)
 */
final class SubmitAttemptHappyPathTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institutionA;
    private Institution $institutionB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institutionA = Institution::factory()->create(['slug' => 'school-a']);
        $this->institutionB = Institution::factory()->create(['slug' => 'school-b']);
    }

    /**
     * Construit un quiz avec 2 questions multiple_choice et leurs réponses
     * (1 bonne par question) — minimum viable pour un grading automatique.
     */
    private function buildQuizWithQuestions(Institution $inst, User $teacher): array
    {
        $quiz = Quiz::factory()->create([
            'institution_id' => $inst->id,
            'created_by' => $teacher->id,
            'passing_score' => 50,
            'duration_minutes' => 30,
            'max_attempts' => 3,
            'shuffle_questions' => false,
            'shuffle_answers' => false,
        ]);

        $q1 = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'institution_id' => $inst->id,
            'type' => 'multiple_choice',
            'points' => 10,
        ]);
        $q1CorrectAnswer = QuizAnswer::create([
            'question_id' => $q1->id,
            'institution_id' => $inst->id,
            'answer_text' => 'Correct answer Q1',
            'is_correct' => true,
            'order' => 1,
        ]);
        QuizAnswer::create([
            'question_id' => $q1->id,
            'institution_id' => $inst->id,
            'answer_text' => 'Wrong answer Q1',
            'is_correct' => false,
            'order' => 2,
        ]);

        $q2 = QuizQuestion::factory()->create([
            'quiz_id' => $quiz->id,
            'institution_id' => $inst->id,
            'type' => 'multiple_choice',
            'points' => 10,
        ]);
        $q2CorrectAnswer = QuizAnswer::create([
            'question_id' => $q2->id,
            'institution_id' => $inst->id,
            'answer_text' => 'Correct answer Q2',
            'is_correct' => true,
            'order' => 1,
        ]);
        QuizAnswer::create([
            'question_id' => $q2->id,
            'institution_id' => $inst->id,
            'answer_text' => 'Wrong answer Q2',
            'is_correct' => false,
            'order' => 2,
        ]);

        return [
            'quiz' => $quiz,
            'questions' => [$q1, $q2],
            'correct_answers' => [
                $q1->id => $q1CorrectAnswer->id,
                $q2->id => $q2CorrectAnswer->id,
            ],
        ];
    }

    public function test_student_can_submit_their_attempt_with_correct_answers(): void
    {
        $teacher = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'enseignant']);
        $student = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'etudiant']);

        $built = $this->buildQuizWithQuestions($this->institutionA, $teacher);

        $attempt = QuizAttempt::factory()->inProgress()->create([
            'quiz_id' => $built['quiz']->id,
            'user_id' => $student->id,
            'institution_id' => $this->institutionA->id,
        ]);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/submit", [
            'answers' => $built['correct_answers'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.score', '100.00')
            ->assertJsonPath('data.passed', true);

        $attempt->refresh();
        $this->assertSame('graded', $attempt->status);
        $this->assertNotNull($attempt->submitted_at);
    }

    public function test_student_submission_with_wrong_answers_fails_passing_threshold(): void
    {
        $teacher = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'enseignant']);
        $student = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'etudiant']);

        $built = $this->buildQuizWithQuestions($this->institutionA, $teacher);

        $attempt = QuizAttempt::factory()->inProgress()->create([
            'quiz_id' => $built['quiz']->id,
            'user_id' => $student->id,
            'institution_id' => $this->institutionA->id,
        ]);

        // Réponses toutes fausses : on choisit un ID inexistant pour chaque question
        $wrongAnswers = [];
        foreach ($built['questions'] as $question) {
            $wrongAnswers[$question->id] = 99999;
        }

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/submit", [
            'answers' => $wrongAnswers,
        ]);

        $response->assertStatus(200);

        $attempt->refresh();
        $this->assertSame(0.0, (float) $attempt->points_earned);
        $this->assertFalse((bool) $attempt->passed);
    }

    public function test_non_owner_cannot_submit_another_students_attempt(): void
    {
        $teacher = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'enseignant']);
        $studentOwner = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'etudiant']);
        $studentAttacker = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'etudiant']);

        $built = $this->buildQuizWithQuestions($this->institutionA, $teacher);
        $attempt = QuizAttempt::factory()->inProgress()->create([
            'quiz_id' => $built['quiz']->id,
            'user_id' => $studentOwner->id,
            'institution_id' => $this->institutionA->id,
        ]);

        Sanctum::actingAs($studentAttacker);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/submit", [
            'answers' => $built['correct_answers'],
        ]);

        $response->assertStatus(403);
    }

    public function test_already_submitted_attempt_cannot_be_resubmitted(): void
    {
        $teacher = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'enseignant']);
        $student = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'etudiant']);

        $built = $this->buildQuizWithQuestions($this->institutionA, $teacher);
        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $built['quiz']->id,
            'user_id' => $student->id,
            'institution_id' => $this->institutionA->id,
            'status' => 'graded',
        ]);

        Sanctum::actingAs($student);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/submit", [
            'answers' => $built['correct_answers'],
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cette tentative a déjà été soumise');
    }

    public function test_non_existent_attempt_returns_404(): void
    {
        $student = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'etudiant']);

        Sanctum::actingAs($student);

        $response = $this->postJson('/api/quiz-attempts/99999/submit', [
            'answers' => ['1' => 1],
        ]);

        $response->assertStatus(404);
    }

    /**
     * Cross-tenant : étudiant de B ne doit pas pouvoir soumettre un attempt
     * appartenant à un étudiant de A. Couvert par la vérification
     * `$attempt->user_id !== $user->id` du controller (et défense en
     * profondeur via le scope `BelongsToInstitution`).
     */
    public function test_cross_tenant_student_cannot_submit_attempt(): void
    {
        $teacherA = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'enseignant']);
        $studentA = User::factory()->create(['institution_id' => $this->institutionA->id, 'role' => 'etudiant']);
        $attackerB = User::factory()->create(['institution_id' => $this->institutionB->id, 'role' => 'etudiant']);

        $built = $this->buildQuizWithQuestions($this->institutionA, $teacherA);
        $attempt = QuizAttempt::factory()->inProgress()->create([
            'quiz_id' => $built['quiz']->id,
            'user_id' => $studentA->id,
            'institution_id' => $this->institutionA->id,
        ]);

        Sanctum::actingAs($attackerB);

        $response = $this->postJson("/api/quiz-attempts/{$attempt->id}/submit", [
            'answers' => $built['correct_answers'],
        ]);

        // 403 (ownership check) OU 404 (scope tenant filtre le find) — les deux
        // sont acceptables : l'attaquant ne peut PAS modifier l'attempt.
        $this->assertContains(
            $response->status(),
            [403, 404],
            'Cross-tenant attacker must be blocked. Got ' . $response->status()
        );

        // Confirmation : l'attempt n'a pas changé d'état
        $attempt->refresh();
        $this->assertSame('in_progress', $attempt->status);
    }
}
