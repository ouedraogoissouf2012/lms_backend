<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\Institution;
use App\Models\Quiz;
use App\Models\QuizAnswer;
use App\Models\QuizQuestion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E2E — Flow 1 (#211) : parcours étudiant quiz COMPLET via l'API.
 *
 * Contrairement aux tests feature unitaires (1 endpoint = 1 test), chaque
 * test enchaîne ici les étapes réelles du parcours utilisateur et vérifie
 * que l'ÉTAT TRANSITE correctement entre les étapes :
 *
 *   liste → détail → start → timer → save-progress → submit → review
 *                                                  → stats quiz recalculées
 *
 * Choix d'implémentation :
 *  - Auth via `Sanctum::actingAs` — le flow `POST /auth/login` passe par le
 *    check-user KLASSCI multi-tenant (HTTP externe) et sera couvert par un
 *    flow E2E dédié avec Http::fake.
 *  - Le quiz est seedé par factories (le flow CRÉATION enseignant = Flow 2).
 *
 * @see docs/IMPROVEMENT_PRIORITIES.md CRITICAL #1 (issue #211)
 * @see app/Services/Quiz/QuizAttemptStartSubmitService.php
 * @see app/Services/Quiz/QuizStatisticsService.php (recompute post-submit, #210)
 */
final class StudentQuizFlowTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->institution = Institution::factory()->create(['slug' => 'school-a']);
        $this->teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);
    }

    /**
     * Quiz publié avec 2 questions multiple_choice (10 pts chacune,
     * 1 bonne réponse par question). Retourne le quiz + le mapping
     * question_id => correct_answer_id pour soumettre un 100%.
     *
     * @return array{quiz: Quiz, correct: array<int, int>, wrong: array<int, int>}
     */
    private function publishedQuiz(int $maxAttempts = 3): array
    {
        $quiz = Quiz::factory()->create([
            'institution_id' => $this->institution->id,
            'created_by' => $this->teacher->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
            'passing_score' => 50,
            'duration_minutes' => 30,
            'max_attempts' => $maxAttempts,
            'shuffle_questions' => false,
            'shuffle_answers' => false,
            'show_correct_answers' => true,
            'allow_review' => true,
        ]);

        $correct = [];
        $wrong = [];

        foreach ([1, 2] as $i) {
            $question = QuizQuestion::factory()->create([
                'quiz_id' => $quiz->id,
                'institution_id' => $this->institution->id,
                'type' => 'multiple_choice',
                'points' => 10,
                'order' => $i,
            ]);

            $good = QuizAnswer::create([
                'question_id' => $question->id,
                'institution_id' => $this->institution->id,
                'answer_text' => "Bonne réponse Q{$i}",
                'is_correct' => true,
                'order' => 1,
            ]);
            $bad = QuizAnswer::create([
                'question_id' => $question->id,
                'institution_id' => $this->institution->id,
                'answer_text' => "Mauvaise réponse Q{$i}",
                'is_correct' => false,
                'order' => 2,
            ]);

            $correct[$question->id] = $good->id;
            $wrong[$question->id] = $bad->id;
        }

        return ['quiz' => $quiz, 'correct' => $correct, 'wrong' => $wrong];
    }

    /**
     * Parcours nominal complet : l'étudiant découvre le quiz, le passe,
     * obtient sa note, revoit sa copie — et les stats agrégées du quiz
     * sont recalculées (chemin QuizStatisticsService::recompute, fix #210).
     */
    public function test_complete_student_quiz_flow(): void
    {
        $built = $this->publishedQuiz();
        $quiz = $built['quiz'];

        Sanctum::actingAs($this->student);

        // ── Étape 1 : le quiz publié apparaît dans la liste étudiante ──────
        $list = $this->getJson('/api/quizzes');
        $list->assertStatus(200);
        $this->assertContains(
            $quiz->id,
            array_column($list->json('data.data') ?? $list->json('data'), 'id'),
            'Le quiz publié doit être visible dans la liste étudiante'
        );

        // ── Étape 2 : détail du quiz — les bonnes réponses sont MASQUÉES ───
        $show = $this->getJson("/api/quizzes/{$quiz->id}");
        $show->assertStatus(200)
            ->assertJsonPath('data.id', $quiz->id)
            ->assertJsonPath('data.user_can_attempt', true)
            ->assertJsonPath('data.user_attempts_count', 0);

        $showAnswers = collect($show->json('data.questions'))
            ->flatMap(fn (array $q): array => $q['answers'] ?? []);
        $this->assertNotEmpty($showAnswers, 'Le détail doit lister les réponses possibles');
        foreach ($showAnswers as $answer) {
            $this->assertArrayNotHasKey(
                'is_correct',
                $answer,
                'SÉCURITÉ : is_correct ne doit JAMAIS fuiter vers un étudiant avant soumission'
            );
        }

        // ── Étape 3 : démarrage de la tentative ────────────────────────────
        $start = $this->postJson("/api/quizzes/{$quiz->id}/start");
        $start->assertStatus(201)
            ->assertJsonPath('success', true);

        $attemptId = $start->json('data.attempt.id');
        $this->assertNotNull($attemptId);
        $this->assertSame('in_progress', $start->json('data.attempt.status'));
        $this->assertIsInt($start->json('data.time_remaining'));

        $startAnswers = collect($start->json('data.questions'))
            ->flatMap(fn (array $q): array => $q['answers'] ?? []);
        foreach ($startAnswers as $answer) {
            $this->assertArrayNotHasKey('is_correct', $answer);
        }

        // ── Étape 4 : le timer répond pendant la tentative ─────────────────
        $timer = $this->getJson("/api/quiz-attempts/{$attemptId}/time-remaining");
        $timer->assertStatus(200)
            ->assertJsonPath('data.is_expired', false);
        $this->assertGreaterThan(0, $timer->json('data.time_remaining_seconds'));

        // ── Étape 5 : sauvegarde intermédiaire (1 réponse sur 2) ───────────
        $firstQuestionId = array_key_first($built['correct']);
        $save = $this->postJson("/api/quiz-attempts/{$attemptId}/save-progress", [
            'answers' => [$firstQuestionId => $built['correct'][$firstQuestionId]],
        ]);
        $save->assertStatus(200)->assertJsonPath('success', true);

        // ── Étape 6 : soumission finale, toutes réponses correctes ─────────
        $submit = $this->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => $built['correct'],
        ]);
        $submit->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.score', '100.00')
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.points_earned', '20.00');

        // La correction est visible après soumission (show_correct_answers).
        $this->assertNotEmpty(
            $submit->json('data.questions_with_results'),
            'La correction doit être renvoyée après soumission (show_correct_answers=true)'
        );

        // ── Étape 7 : stats agrégées du quiz recalculées (#210 + fix E2E) ──
        // Le fix E2E #211 inclut les tentatives `graded` (grading auto) dans
        // attempts_count / average_score — avant, les deux restaient à vide.
        $quiz->refresh();
        $this->assertSame(2, $quiz->total_questions);
        $this->assertSame('20.00', (string) $quiz->total_points);
        $this->assertSame(1, $quiz->attempts_count);
        $this->assertSame('100.00', (string) $quiz->average_score);

        // ── Étape 8 : review de la tentative (allow_review=true) ───────────
        $review = $this->getJson("/api/quiz-attempts/{$attemptId}");
        $review->assertStatus(200)
            ->assertJsonPath('data.id', $attemptId)
            ->assertJsonPath('data.status', 'graded');
        $this->assertNotEmpty($review->json('data.questions_with_results'));

        // Le quota de tentatives a bien été consommé dans le détail quiz.
        $showAfter = $this->getJson("/api/quizzes/{$quiz->id}");
        $this->assertSame($attemptId, $showAfter->json('data.user_latest_attempt.id'));
    }

    /**
     * Le quota max_attempts est appliqué au fil du parcours : après avoir
     * épuisé sa seule tentative, l'étudiant ne peut plus redémarrer.
     */
    public function test_student_cannot_exceed_max_attempts_through_api_flow(): void
    {
        $built = $this->publishedQuiz(maxAttempts: 1);
        $quiz = $built['quiz'];

        Sanctum::actingAs($this->student);

        // Tentative 1 : start + submit (réponses fausses → failed, peu importe).
        $start = $this->postJson("/api/quizzes/{$quiz->id}/start");
        $start->assertStatus(201);
        $attemptId = $start->json('data.attempt.id');

        $this->postJson("/api/quiz-attempts/{$attemptId}/submit", [
            'answers' => $built['wrong'],
        ])->assertStatus(200)
          ->assertJsonPath('data.passed', false);

        // Tentative 2 : refusée — quota épuisé.
        $this->postJson("/api/quizzes/{$quiz->id}/start")
            ->assertStatus(403);
    }

    /**
     * Un quiz en brouillon est invisible sur TOUT le parcours étudiant :
     * absent de la liste, détail refusé, démarrage refusé.
     */
    public function test_draft_quiz_is_invisible_throughout_student_flow(): void
    {
        $draft = Quiz::factory()->create([
            'institution_id' => $this->institution->id,
            'created_by' => $this->teacher->id,
            'status' => 'draft',
            'published_at' => null,
        ]);

        Sanctum::actingAs($this->student);

        $list = $this->getJson('/api/quizzes');
        $list->assertStatus(200);
        $this->assertNotContains(
            $draft->id,
            array_column($list->json('data.data') ?? $list->json('data'), 'id'),
            'Un brouillon ne doit pas apparaître dans la liste étudiante'
        );

        $this->getJson("/api/quizzes/{$draft->id}")
            ->assertStatus(403);

        $this->postJson("/api/quizzes/{$draft->id}/start")
            ->assertStatus(403);
    }
}
