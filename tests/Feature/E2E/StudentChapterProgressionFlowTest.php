<?php

declare(strict_types=1);

namespace Tests\Feature\E2E;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * E2E — Flow 3 (#211) : progression séquentielle dans les chapitres d'une
 * leçon, avec quiz obligatoire BLOQUANT.
 *
 * Parcours vérifié de bout en bout :
 *
 *   ch1 (texte) → ch2 (quiz obligatoire) → ch3 (texte)
 *
 *   progression 0% → complète ch1 → ch3 BLOQUÉ (quiz pas réussi)
 *   → échoue le quiz → ch3 toujours bloqué → réussit le quiz
 *   → ch2 auto-complété → complète ch3 → 100%
 *
 * L'enjeu : `ChapterAccessGate` (extrait en #223) est LE verrou pédagogique —
 * s'il fuit, un étudiant saute les évaluations obligatoires.
 *
 * @see docs/IMPROVEMENT_PRIORITIES.md CRITICAL #1 (issue #211)
 * @see app/Services/Chapter/ChapterAccessGate.php
 * @see app/Services/Chapter/ChapterProgressService.php (recordQuizScore)
 * @see app/Services/KnowledgeCheck/KnowledgeCheckAttemptService.php
 */
final class StudentChapterProgressionFlowTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $teacher;
    private User $student;
    private Lesson $lesson;
    private Chapter $ch1;
    private Chapter $ch2;
    private Chapter $ch3;
    private KnowledgeCheck $quiz;

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

        $this->lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $this->teacher->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);

        $this->ch1 = Chapter::factory()->create([
            'lesson_id' => $this->lesson->id,
            'institution_id' => $this->institution->id,
            'enseignant_id' => $this->teacher->id,
            'content_type' => 'text',
            'order' => 0,
        ]);
        $this->ch2 = Chapter::factory()->create([
            'lesson_id' => $this->lesson->id,
            'institution_id' => $this->institution->id,
            'enseignant_id' => $this->teacher->id,
            'content_type' => 'quiz',
            'order' => 1,
        ]);
        $this->ch3 = Chapter::factory()->create([
            'lesson_id' => $this->lesson->id,
            'institution_id' => $this->institution->id,
            'enseignant_id' => $this->teacher->id,
            'content_type' => 'text',
            'order' => 2,
        ]);

        // Quiz obligatoire (is_required) sur le chapitre 2 — 1 question,
        // passing_score 70 → il faut 100% pour réussir.
        $this->quiz = KnowledgeCheck::factory()->create([
            'chapter_id' => $this->ch2->id,
            'institution_id' => $this->institution->id,
            'is_active' => true,
            'is_required' => true,
            'passing_score' => 70,
            'shuffle_questions' => false,
            'questions' => [
                [
                    'question' => 'Quelle est la capitale du Burkina Faso ?',
                    'type' => 'single',
                    'options' => ['Bobo-Dioulasso', 'Ouagadougou', 'Koudougou'],
                    'correct_answer' => 'Ouagadougou',
                    'explanation' => 'Ouagadougou est la capitale.',
                    'points' => 1,
                ],
            ],
        ]);
    }

    /**
     * Parcours complet : le quiz obligatoire verrouille la suite tant qu'il
     * n'est pas réussi, puis la progression atteint 100%.
     */
    public function test_quiz_gate_blocks_then_unlocks_chapter_progression(): void
    {
        Sanctum::actingAs($this->student);

        // ── Étape 1 : progression initiale = 0 ─────────────────────────────
        $initial = $this->getJson("/api/lessons/{$this->lesson->id}/chapter-progress");
        $initial->assertStatus(200)
            ->assertJsonPath('data.total_chapters', 3)
            ->assertJsonPath('data.completed_chapters', 0)
            ->assertJsonPath('data.percentage', 0);

        // ── Étape 2 : le chapitre 1 est accessible, le 3 est verrouillé ────
        $this->getJson("/api/chapters/{$this->ch1->id}/progress")
            ->assertStatus(200)
            ->assertJsonPath('data.can_access', true);

        $ch3Before = $this->getJson("/api/chapters/{$this->ch3->id}/progress");
        $ch3Before->assertStatus(200)
            ->assertJsonPath('data.can_access', false);
        $this->assertSame(
            $this->ch2->id,
            $ch3Before->json('data.blocked_by_quiz.chapter_id'),
            'Le blocage doit désigner le chapitre-quiz obligatoire'
        );

        // ── Étape 3 : complète ch1 → 33% ────────────────────────────────────
        $this->postJson("/api/chapters/{$this->ch1->id}/complete", [
            'time_spent_seconds' => 120,
        ])->assertStatus(200);

        $afterCh1 = $this->getJson("/api/lessons/{$this->lesson->id}/chapter-progress");
        $afterCh1->assertJsonPath('data.completed_chapters', 1)
            ->assertJsonPath('data.percentage', 33);

        // ── Étape 4 : compléter ch3 directement = REFUSÉ (quiz pas réussi) ─
        $this->postJson("/api/chapters/{$this->ch3->id}/complete")
            ->assertStatus(403);

        // ── Étape 5 : échec au quiz → ch3 reste verrouillé ──────────────────
        $start = $this->postJson("/api/knowledge-checks/{$this->quiz->id}/start");
        $start->assertStatus(200);
        // Les bonnes réponses ne fuient pas dans le payload de démarrage.
        foreach ($start->json('data.questions') as $question) {
            $this->assertArrayNotHasKey('correct_answer', $question);
        }

        $fail = $this->postJson("/api/knowledge-checks/{$this->quiz->id}/submit", [
            'answers' => [0 => 'Bobo-Dioulasso'],
            'time_spent_seconds' => 30,
        ]);
        $fail->assertStatus(200)
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.score', 0);

        $this->postJson("/api/chapters/{$this->ch3->id}/complete")
            ->assertStatus(403);

        // ── Étape 6 : réussite au quiz → ch2 auto-complété ──────────────────
        $pass = $this->postJson("/api/knowledge-checks/{$this->quiz->id}/submit", [
            'answers' => [0 => 'Ouagadougou'],
            'time_spent_seconds' => 45,
        ]);
        $pass->assertStatus(200)
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.score', 100);

        $afterQuiz = $this->getJson("/api/lessons/{$this->lesson->id}/chapter-progress");
        $afterQuiz->assertJsonPath('data.completed_chapters', 2)
            ->assertJsonPath('data.percentage', 67);

        // ── Étape 7 : ch3 déverrouillé → complétion → 100% ─────────────────
        $this->getJson("/api/chapters/{$this->ch3->id}/progress")
            ->assertStatus(200)
            ->assertJsonPath('data.can_access', true);

        $this->postJson("/api/chapters/{$this->ch3->id}/complete", [
            'time_spent_seconds' => 300,
        ])->assertStatus(200);

        $final = $this->getJson("/api/lessons/{$this->lesson->id}/chapter-progress");
        $final->assertJsonPath('data.completed_chapters', 3)
            ->assertJsonPath('data.percentage', 100);

        // L'historique des tentatives reflète les 2 passages (échec + réussite).
        $attempts = $this->getJson("/api/knowledge-checks/{$this->quiz->id}/my-attempts");
        $attempts->assertStatus(200)
            ->assertJsonPath('data.has_passed', true)
            ->assertJsonPath('data.best_score', 100);
        $this->assertCount(2, $attempts->json('data.attempts'));
    }

    /**
     * Le temps passé s'accumule chapitre par chapitre via POST /time.
     */
    public function test_time_tracking_accumulates_across_visits(): void
    {
        Sanctum::actingAs($this->student);

        $this->postJson("/api/chapters/{$this->ch1->id}/time", [
            'time_spent_seconds' => 60,
        ])->assertStatus(200)
          ->assertJsonPath('data.time_spent_seconds', 60);

        $this->postJson("/api/chapters/{$this->ch1->id}/time", [
            'time_spent_seconds' => 90,
        ])->assertStatus(200)
          ->assertJsonPath('data.time_spent_seconds', 150);
    }
}
