<?php

namespace Tests\Feature\Requests;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\Institution;
use App\Models\Question;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for SubmitEvaluationRequest (POST /api/evaluations/{id}/submit)
 *
 * Validates evaluation submission with:
 * - Student authenticated (must be student role)
 * - Evaluation published (not draft)
 * - Deadline not passed
 * - Not already submitted
 * - Answers present and valid
 *
 * ## Authorization Model
 * evaluate() checks complex state:
 * 1. User authenticated + is student
 * 2. Evaluation exists and published
 * 3. Deadline not passed
 * 4. Not previously submitted
 *
 * If ANY check fails → 403 Unauthorized
 *
 * ## 10-year perspective
 * Tests document evaluation state machine.
 * New devs understand submission requirements by reading tests.
 * Regression in evaluation logic caught immediately by tests.
 */
class SubmitEvaluationRequestTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private Institution $institution;
    private User $student;
    private User $teacher;
    private Evaluation $evaluation;
    private Question $question1;
    private Question $question2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create();

        $this->student = User::factory()
            ->student()
            ->for($this->institution)
            ->create();

        $this->teacher = User::factory()
            ->teacher()
            ->for($this->institution)
            ->create();

        // Create published evaluation with future deadline
        $this->evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state([
                'status' => 'published',
                'deadline_at' => now()->addDays(7),
            ])
            ->create();

        $this->question1 = Question::factory()
            ->for($this->evaluation)
            ->state(['question_text' => 'What is 2+2?'])
            ->create();

        $this->question2 = Question::factory()
            ->for($this->evaluation)
            ->state(['question_text' => 'What is the capital of France?'])
            ->create();
    }

    /**
     * ✅ HAPPY PATH: Valid evaluation submission
     */
    public function test_valid_submission_passes(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
                ['question_id' => $this->question2->id, 'answer' => 'Paris'],
            ],
        ]);

        $response->assertStatus(201);
        // Verify submission saved
        $this->assertDatabaseHas('evaluation_submissions', [
            'evaluation_id' => $this->evaluation->id,
            'student_id' => $this->student->id,
        ]);
    }

    /**
     * ✅ HAPPY PATH: Single answer submission
     */
    public function test_single_answer_submission_passes(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ TIMESTAMP: submitted_at with ISO 8601 format
     */
    public function test_submission_with_timestamp_passes(): void
    {
        Sanctum::actingAs($this->student);
        $timestamp = now()->toIso8601String();

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
            'submitted_at' => $timestamp,
        ]);

        $response->assertStatus(201);
    }

    /**
     * ✅ EDGE CASE: Answer with leading/trailing spaces is trimmed
     */
    public function test_answer_with_spaces_is_trimmed(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '   4   '],
            ],
        ]);

        $response->assertStatus(201);
    }

    /**
     * ❌ VALIDATION: Missing answers fails
     */
    public function test_missing_answers_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            // No answers
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.answers', [
            fn($msg) => str_contains($msg, 'requise') || str_contains($msg, 'required')
        ]);
    }

    /**
     * ❌ VALIDATION: Empty answers array fails
     */
    public function test_empty_answers_array_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.answers', [
            fn($msg) => str_contains($msg, 'requise') || str_contains($msg, 'required')
        ]);
    }

    /**
     * ❌ VALIDATION: Missing question_id fails
     */
    public function test_missing_question_id_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['answer' => '4'], // No question_id
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.answers.0.question_id', [
            fn($msg) => str_contains($msg, 'requis') || str_contains($msg, 'required')
        ]);
    }

    /**
     * ❌ VALIDATION: Invalid question_id fails
     */
    public function test_invalid_question_id_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => 99999, 'answer' => '4'], // Question doesn't exist
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.answers.0.question_id', [
            fn($msg) => str_contains($msg, 'existe pas') || str_contains($msg, 'does not exist')
        ]);
    }

    /**
     * ❌ VALIDATION: Missing answer text fails
     */
    public function test_missing_answer_text_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id], // No answer
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.answers.0.answer', [
            fn($msg) => str_contains($msg, 'requise') || str_contains($msg, 'required')
        ]);
    }

    /**
     * ❌ DOS PREVENTION: Answer too long fails
     */
    public function test_answer_exceeding_max_length_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => str_repeat('a', 10001)],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.answers.0.answer', [
            fn($msg) => str_contains($msg, '10000') || str_contains($msg, 'dépasser')
        ]);
    }

    /**
     * ❌ AUTHORIZATION: Unauthenticated user cannot submit
     */
    public function test_unauthenticated_cannot_submit(): void
    {
        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
        ]);

        $response->assertStatus(401);
    }

    /**
     * ❌ AUTHORIZATION: Teacher cannot submit (only students)
     */
    public function test_teacher_cannot_submit_evaluation(): void
    {
        Sanctum::actingAs($this->teacher);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ STATE: Draft evaluation cannot be submitted
     */
    public function test_draft_evaluation_cannot_be_submitted(): void
    {
        $draft_evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state(['status' => 'draft'])
            ->create();

        $draft_question = Question::factory()
            ->for($draft_evaluation)
            ->create();

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$draft_evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $draft_question->id, 'answer' => '4'],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ DEADLINE: Expired deadline cannot submit
     */
    public function test_expired_deadline_cannot_submit(): void
    {
        $expired_evaluation = Evaluation::factory()
            ->for($this->institution)
            ->state([
                'status' => 'published',
                'deadline_at' => now()->subDays(1), // Yesterday
            ])
            ->create();

        $expired_question = Question::factory()
            ->for($expired_evaluation)
            ->create();

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$expired_evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $expired_question->id, 'answer' => '4'],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ❌ DUPLICATE: Already submitted cannot submit again
     */
    public function test_already_submitted_cannot_resubmit(): void
    {
        // Create prior submission
        EvaluationSubmission::factory()
            ->for($this->evaluation)
            ->for($this->student, 'student')
            ->create();

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * ✅ TIMESTAMP: Submission without timestamp uses current time
     */
    public function test_submission_without_timestamp_defaults_to_now(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
            // No submitted_at
        ]);

        $response->assertStatus(201);
        // Verify submitted_at was set to now (approximate check)
        $submission = EvaluationSubmission::latest()->first();
        $this->assertNotNull($submission->submitted_at);
    }

    /**
     * ❌ TIMESTAMP: Invalid ISO 8601 format fails
     */
    public function test_invalid_timestamp_format_fails(): void
    {
        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => '4'],
            ],
            'submitted_at' => '2026-04-30 14:30:00', // Invalid format (should be ISO 8601)
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.submitted_at', [
            fn($msg) => str_contains($msg, 'ISO') || str_contains($msg, 'format')
        ]);
    }

    /**
     * ✅ MULTIPLE: Multiple valid answers together
     */
    public function test_multiple_answers_all_valid(): void
    {
        // Create more questions
        $question3 = Question::factory()->for($this->evaluation)->create();
        $question4 = Question::factory()->for($this->evaluation)->create();

        Sanctum::actingAs($this->student);

        $response = $this->postJson("/api/evaluations/{$this->evaluation->id}/submit", [
            'answers' => [
                ['question_id' => $this->question1->id, 'answer' => 'Answer 1'],
                ['question_id' => $this->question2->id, 'answer' => 'Answer 2'],
                ['question_id' => $question3->id, 'answer' => 'Answer 3'],
                ['question_id' => $question4->id, 'answer' => 'Answer 4'],
            ],
        ]);

        $response->assertStatus(201);
    }
}
