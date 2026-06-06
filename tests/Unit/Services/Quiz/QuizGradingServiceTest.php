<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Services\Quiz\QuizGradingService;
use App\Services\Quiz\QuizStatisticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre les 5 méthodes publiques de {@see QuizGradingService} (M5 audit).
 *
 * Le service est le code-le-plus-risqué du domaine quiz (calcul des scores
 * étudiants). Les tests Feature `SubmitAttemptHappyPathTest` couvrent le HTTP,
 * ces tests unitaires couvrent la logique métier directement.
 */
final class QuizGradingServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuizGradingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QuizGradingService(new QuizStatisticsService());
    }

    /** @test */
    public function checkAnswer_multiple_choice_returns_true_when_user_picks_correct_answer(): void
    {
        $question = QuizQuestion::factory()->multipleChoice()->create();
        $correct = QuizAnswer::create([
            'question_id' => $question->id,
            'answer_text' => 'A',
            'is_correct' => true,
            'order' => 1,
        ]);
        QuizAnswer::create([
            'question_id' => $question->id,
            'answer_text' => 'B',
            'is_correct' => false,
            'order' => 2,
        ]);
        $question->load('answers');

        $this->assertTrue($this->service->checkAnswer($question, $correct->id));
    }

    /** @test */
    public function checkAnswer_multiple_choice_returns_false_when_user_picks_wrong_answer(): void
    {
        $question = QuizQuestion::factory()->multipleChoice()->create();
        QuizAnswer::create([
            'question_id' => $question->id,
            'answer_text' => 'A',
            'is_correct' => true,
            'order' => 1,
        ]);
        $wrong = QuizAnswer::create([
            'question_id' => $question->id,
            'answer_text' => 'B',
            'is_correct' => false,
            'order' => 2,
        ]);
        $question->load('answers');

        $this->assertFalse($this->service->checkAnswer($question, $wrong->id));
    }

    /** @test */
    public function checkAnswer_multiple_response_returns_true_only_on_exact_set_match(): void
    {
        $question = QuizQuestion::factory()->create(['type' => 'multiple_response']);
        $a = QuizAnswer::create(['question_id' => $question->id, 'answer_text' => 'A', 'is_correct' => true, 'order' => 1]);
        $b = QuizAnswer::create(['question_id' => $question->id, 'answer_text' => 'B', 'is_correct' => true, 'order' => 2]);
        QuizAnswer::create(['question_id' => $question->id, 'answer_text' => 'C', 'is_correct' => false, 'order' => 3]);
        $question->load('answers');

        // Exact correct set → true
        $this->assertTrue($this->service->checkAnswer($question, [$a->id, $b->id]));

        // Partial answer (missing one) → false
        $this->assertFalse($this->service->checkAnswer($question, [$a->id]));

        // Extra wrong answer → false
        $this->assertFalse($this->service->checkAnswer($question, [$a->id, $b->id, 999]));
    }

    /** @test */
    public function checkAnswer_true_false_compares_against_correct_answer(): void
    {
        $question = QuizQuestion::factory()->trueFalse()->create();
        $correct = QuizAnswer::create([
            'question_id' => $question->id,
            'answer_text' => 'Vrai',
            'is_correct' => true,
            'order' => 1,
        ]);
        $wrong = QuizAnswer::create([
            'question_id' => $question->id,
            'answer_text' => 'Faux',
            'is_correct' => false,
            'order' => 2,
        ]);
        $question->load('answers');

        $this->assertTrue($this->service->checkAnswer($question, $correct->id));
        $this->assertFalse($this->service->checkAnswer($question, $wrong->id));
    }

    /** @test */
    public function checkAnswer_returns_null_for_short_answer_and_essay(): void
    {
        $shortAnswer = QuizQuestion::factory()->shortAnswer()->create();
        $shortAnswer->load('answers');
        $essay = QuizQuestion::factory()->create(['type' => 'essay']);
        $essay->load('answers');

        $this->assertNull($this->service->checkAnswer($shortAnswer, 'whatever'));
        $this->assertNull($this->service->checkAnswer($essay, 'whatever'));
    }

    /** @test */
    public function calculatePoints_returns_question_points_when_correct(): void
    {
        $question = QuizQuestion::factory()->multipleChoice()->create(['points' => 5]);
        $correct = QuizAnswer::create([
            'question_id' => $question->id,
            'answer_text' => 'A',
            'is_correct' => true,
            'order' => 1,
        ]);
        $question->load('answers');

        $this->assertSame(5.0, $this->service->calculatePoints($question, $correct->id));
    }

    /** @test */
    public function calculatePoints_returns_zero_when_incorrect_or_requires_manual(): void
    {
        $mc = QuizQuestion::factory()->multipleChoice()->create(['points' => 5]);
        QuizAnswer::create(['question_id' => $mc->id, 'answer_text' => 'A', 'is_correct' => true, 'order' => 1]);
        $wrong = QuizAnswer::create(['question_id' => $mc->id, 'answer_text' => 'B', 'is_correct' => false, 'order' => 2]);
        $mc->load('answers');

        $essay = QuizQuestion::factory()->create(['type' => 'essay', 'points' => 5]);
        $essay->load('answers');

        $this->assertSame(0.0, $this->service->calculatePoints($mc, $wrong->id));
        // short_answer / essay returns null → 0 points (graded manually later)
        $this->assertSame(0.0, $this->service->calculatePoints($essay, 'response text'));
    }

    /** @test */
    public function gradeAttempt_auto_grades_when_all_questions_are_auto(): void
    {
        $quiz = Quiz::factory()->create(['passing_score' => 50]);
        $q1 = QuizQuestion::factory()->multipleChoice()->create(['quiz_id' => $quiz->id, 'points' => 10]);
        $a1 = QuizAnswer::create(['question_id' => $q1->id, 'answer_text' => 'A', 'is_correct' => true, 'order' => 1]);
        $q2 = QuizQuestion::factory()->multipleChoice()->create(['quiz_id' => $quiz->id, 'points' => 10]);
        $a2 = QuizAnswer::create(['question_id' => $q2->id, 'answer_text' => 'A', 'is_correct' => true, 'order' => 1]);

        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'answers' => [$q1->id => $a1->id, $q2->id => $a2->id],
        ]);

        $this->service->gradeAttempt($attempt);

        $this->assertEquals(20.0, $attempt->points_earned);
        $this->assertEquals(20.0, $attempt->points_possible);
        $this->assertEquals(100.0, $attempt->score);
        $this->assertTrue($attempt->passed);
        $this->assertSame('graded', $attempt->status);
    }

    /** @test */
    public function gradeAttempt_sets_status_submitted_when_manual_grading_required(): void
    {
        $quiz = Quiz::factory()->create();
        QuizQuestion::factory()->multipleChoice()->create(['quiz_id' => $quiz->id, 'points' => 10]);
        QuizQuestion::factory()->create(['quiz_id' => $quiz->id, 'type' => 'essay', 'points' => 10]);

        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'answers' => [],
        ]);

        $this->service->gradeAttempt($attempt);

        $this->assertSame('submitted', $attempt->status, 'Status must be "submitted" (manual review pending) when any question requires manual grading');
    }

    /** @test */
    public function submitAttempt_captures_answers_time_spent_and_recomputes_quiz_stats(): void
    {
        $quiz = Quiz::factory()->create(['passing_score' => 50]);
        $q1 = QuizQuestion::factory()->multipleChoice()->create(['quiz_id' => $quiz->id, 'points' => 10]);
        $a1 = QuizAnswer::create(['question_id' => $q1->id, 'answer_text' => 'A', 'is_correct' => true, 'order' => 1]);

        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'status' => 'in_progress',
            'started_at' => now()->subMinutes(5),
            'submitted_at' => null,
        ]);

        $this->service->submitAttempt($attempt, [$q1->id => $a1->id]);

        $attempt->refresh();
        $this->assertSame('graded', $attempt->status);
        $this->assertNotNull($attempt->submitted_at);
        $this->assertGreaterThan(0, $attempt->time_spent_seconds);

        // Stats recompute : total_questions/total_points doivent refléter le quiz.
        // (Note: attempts_count ne compte que `status='submitted'`, pas `'graded'`
        // — bug latent du code original préservé verbatim, non corrigé ici.)
        $quiz->refresh();
        $this->assertSame(1, $quiz->total_questions);
        $this->assertEquals(10.0, $quiz->total_points);
    }

    /** @test */
    public function manualGradeAttempt_sets_points_score_passed_grader_and_recomputes_stats(): void
    {
        $quiz = Quiz::factory()->create(['passing_score' => 50]);
        $teacher = User::factory()->create();

        $attempt = QuizAttempt::factory()->create([
            'quiz_id' => $quiz->id,
            'points_possible' => 20,
        ]);

        $this->service->manualGradeAttempt($attempt, 15.0, $teacher->id, 'Bon travail');

        $attempt->refresh();
        $this->assertEquals(15.0, $attempt->points_earned);
        $this->assertEquals(75.0, $attempt->score);
        $this->assertTrue($attempt->passed);
        $this->assertSame('graded', $attempt->status);
        $this->assertEquals($teacher->id, $attempt->graded_by);
        $this->assertNotNull($attempt->graded_at);
        $this->assertSame('Bon travail', $attempt->teacher_feedback);
    }
}
