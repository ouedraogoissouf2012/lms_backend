<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Quiz;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Services\Quiz\QuizCrudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * #546 — Correctness du batching de `QuizCrudService::list()` : le nouveau
 * calcul en 1 requête (`QuizAccessService::consumingAttemptsByQuiz`) doit
 * produire EXACTEMENT les mêmes valeurs que l'ancien calcul par-quiz
 * (`attemptsCountForUser`/`canUserAttempt`/`bestAttemptForUser`).
 *
 * @see app/Services/Quiz/QuizCrudService.php
 * @see app/Services/Quiz/QuizAccessService.php
 */
final class QuizCrudServiceListBatchedAttemptsTest extends TestCase
{
    use RefreshDatabase;

    private QuizCrudService $service;
    private Institution $institution;
    private User $student;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->app->make(QuizCrudService::class);
        $this->institution = Institution::factory()->create();
        $this->student = User::factory()->student()->for($this->institution)->create();
        $this->teacher = User::factory()->teacher()->for($this->institution)->create();
    }

    private function publishedQuiz(int $maxAttempts = 3): Quiz
    {
        return Quiz::factory()->forTeacher($this->teacher)->create([
            'institution_id' => $this->institution->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'max_attempts' => $maxAttempts,
        ]);
    }

    /**
     * MISE À JOUR #581 — ce test verrouillait le défaut.
     *
     * Il asseyait qu'une tentative `in_progress` n'était PAS comptée (2 sur 3).
     * C'est précisément ce qui rendait `max_attempts` contournable : trois
     * onglets ouvraient trois tentatives notables sur un quiz à
     * `max_attempts = 1`. Le comptage inclut désormais tout sauf `abandoned`.
     * L'intention d'origine — ne compter que les tentatives de CET étudiant sur
     * CE quiz — est préservée, y compris son assertion anti-fuite.
     */
    public function test_attempts_count_includes_every_non_abandoned_attempt(): void
    {
        $quiz = $this->publishedQuiz();
        QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->create([
            'institution_id' => $this->institution->id,
            'status' => 'submitted',
            'attempt_number' => 1,
        ]);
        QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->create([
            'institution_id' => $this->institution->id,
            'status' => 'graded',
            'attempt_number' => 2,
        ]);
        QuizAttempt::factory()->inProgress()->forQuiz($quiz)->byUser($this->student)->create([
            'institution_id' => $this->institution->id,
            'attempt_number' => 3,
        ]);
        // Bruit : tentative d'un AUTRE étudiant sur le même quiz — ne doit pas compter.
        $otherStudent = User::factory()->student()->for($this->institution)->create();
        QuizAttempt::factory()->forQuiz($quiz)->byUser($otherStudent)->create([
            'institution_id' => $this->institution->id,
            'status' => 'submitted',
            'attempt_number' => 1,
        ]);

        $page = $this->service->list($this->student, []);
        $result = $page->getCollection()->firstWhere('id', $quiz->id);

        self::assertSame(3, $result->user_attempts_count);
        self::assertTrue(
            $result->user_can_attempt,
            'Une tentative en cours reste reprenable — la liste ne doit pas annoncer le contraire.',
        );
    }

    public function test_can_attempt_is_false_once_quota_reached(): void
    {
        $quiz = $this->publishedQuiz(maxAttempts: 2);
        QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->create([
            'institution_id' => $this->institution->id,
            'status' => 'submitted',
            'attempt_number' => 1,
        ]);
        QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->create([
            'institution_id' => $this->institution->id,
            'status' => 'submitted',
            'attempt_number' => 2,
        ]);

        $page = $this->service->list($this->student, []);
        $result = $page->getCollection()->firstWhere('id', $quiz->id);

        self::assertSame(2, $result->user_attempts_count);
        self::assertFalse($result->user_can_attempt);
    }

    public function test_can_attempt_is_false_when_quiz_not_available(): void
    {
        $unpublished = Quiz::factory()->forTeacher($this->teacher)->create([
            'institution_id' => $this->institution->id,
            'status' => 'draft',
            'published_at' => null,
            'max_attempts' => 3,
        ]);

        $page = $this->service->list($this->teacher, []);
        $result = $page->getCollection()->firstWhere('id', $unpublished->id);

        self::assertFalse($result->user_can_attempt);
    }

    public function test_best_attempt_is_the_highest_scored_finalized_attempt(): void
    {
        $quiz = $this->publishedQuiz();
        QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->withScore(40)->create([
            'institution_id' => $this->institution->id,
            'status' => 'submitted',
            'attempt_number' => 1,
        ]);
        $best = QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->withScore(95)->create([
            'institution_id' => $this->institution->id,
            'status' => 'graded',
            'attempt_number' => 2,
        ]);
        QuizAttempt::factory()->forQuiz($quiz)->byUser($this->student)->withScore(60)->create([
            'institution_id' => $this->institution->id,
            'status' => 'submitted',
            'attempt_number' => 3,
        ]);

        $page = $this->service->list($this->student, []);
        $result = $page->getCollection()->firstWhere('id', $quiz->id);

        self::assertNotNull($result->user_best_attempt);
        self::assertSame($best->id, $result->user_best_attempt->id);
        self::assertEquals(95, $result->user_best_attempt->score);
    }

    public function test_attempts_of_one_quiz_never_leak_into_another_quiz_on_the_same_page(): void
    {
        $quizA = $this->publishedQuiz();
        $quizB = $this->publishedQuiz();
        foreach ([1, 2, 3] as $attemptNumber) {
            QuizAttempt::factory()->forQuiz($quizA)->byUser($this->student)->create([
                'institution_id' => $this->institution->id,
                'status' => 'submitted',
                'attempt_number' => $attemptNumber,
            ]);
        }
        // Aucune tentative sur quizB.

        $page = $this->service->list($this->student, []);
        $resultA = $page->getCollection()->firstWhere('id', $quizA->id);
        $resultB = $page->getCollection()->firstWhere('id', $quizB->id);

        self::assertSame(3, $resultA->user_attempts_count);
        self::assertSame(0, $resultB->user_attempts_count);
        self::assertNull($resultB->user_best_attempt);
    }

    public function test_quiz_without_any_attempt_has_zero_count_and_no_best_attempt(): void
    {
        $quiz = $this->publishedQuiz();

        $page = $this->service->list($this->student, []);
        $result = $page->getCollection()->firstWhere('id', $quiz->id);

        self::assertSame(0, $result->user_attempts_count);
        self::assertNull($result->user_best_attempt);
        self::assertTrue($result->user_can_attempt);
    }
}
