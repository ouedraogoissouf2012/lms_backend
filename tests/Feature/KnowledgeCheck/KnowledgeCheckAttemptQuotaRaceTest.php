<?php

declare(strict_types=1);

namespace Tests\Feature\KnowledgeCheck;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * #540 — quota de tentatives knowledge-check : filet base + application réelle.
 *
 * État constaté avant correctif (sonde exécutée, `max_attempts = 1`) :
 *
 * ```
 * [P2] submit #1 status=200
 * [P2] submit #2 status=200
 * [P2] submit #3 status=200
 * [P2] attempts persisted=3 (max_attempts=1)
 * ```
 *
 * `canAttempt()` n'était consulté qu'au `start`, lequel ne persiste rien : le
 * quota était purement décoratif et se contournait **sans aucune concurrence**.
 * La table n'avait par ailleurs ni `attempt_number` ni index unique — donc aucun
 * filet base.
 *
 * @see app/Services/KnowledgeCheck/KnowledgeCheckAttemptService.php
 */
final class KnowledgeCheckAttemptQuotaRaceTest extends TestCase
{
    use RefreshDatabase;

    private Institution $institution;
    private User $student;
    private Chapter $chapter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->disableKlassciMiddleware();

        $this->institution = Institution::factory()->create();
        $teacher = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'enseignant',
        ]);
        $this->student = User::factory()->create([
            'institution_id' => $this->institution->id,
            'role' => 'etudiant',
        ]);

        $lesson = Lesson::factory()->create([
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'status' => 'published',
        ]);
        $this->chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $this->institution->id,
            'enseignant_id' => $teacher->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);
    }

    private function quiz(?int $maxAttempts): KnowledgeCheck
    {
        return KnowledgeCheck::factory()->create([
            'chapter_id' => $this->chapter->id,
            'institution_id' => $this->institution->id,
            'is_active' => true,
            'max_attempts' => $maxAttempts,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function answers(): array
    {
        return ['answers' => ['0' => '4', '1' => 'True'], 'time_spent_seconds' => 10];
    }

    // ───────────────────────── R1.1 — filet base ─────────────────────────

    /**
     * Garde STRUCTURELLE : l'unicité doit exister au niveau du schéma, pas
     * seulement dans le service. Un correctif applicatif seul laisserait passer
     * toute écriture hors du service (import, commande artisan, autre endpoint).
     */
    public function test_la_table_porte_un_index_unique_sur_le_numero_de_tentative(): void
    {
        $this->assertTrue(
            Schema::hasColumn('knowledge_check_attempts', 'attempt_number'),
            'La colonne attempt_number est absente : aucun filet base possible.',
        );

        $uniques = collect(Schema::getIndexes('knowledge_check_attempts'))
            ->filter(static fn (array $index): bool => $index['unique'] === true)
            ->map(static function (array $index): array {
                $columns = $index['columns'];
                sort($columns);

                return $columns;
            })
            ->values()
            ->all();

        $this->assertContains(
            ['attempt_number', 'knowledge_check_id', 'user_id'],
            $uniques,
            'Aucun index unique (knowledge_check_id, user_id, attempt_number).',
        );
    }

    public function test_la_base_rejette_deux_tentatives_de_meme_numero(): void
    {
        $quiz = $this->quiz(maxAttempts: 5);

        $this->persistAttempt($quiz, attemptNumber: 1);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->persistAttempt($quiz, attemptNumber: 1);
    }

    // ──────────────────── R4.2 — quota appliqué au submit ────────────────────

    public function test_le_quota_refuse_la_seconde_soumission_et_ne_persiste_rien(): void
    {
        $quiz = $this->quiz(maxAttempts: 1);
        Sanctum::actingAs($this->student);

        $this->postJson("/api/knowledge-checks/{$quiz->id}/submit", $this->answers())
            ->assertStatus(200);

        $refused = $this->postJson("/api/knowledge-checks/{$quiz->id}/submit", $this->answers());

        $refused->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'Nombre maximum de tentatives atteint',
            ]);

        $this->assertSame(1, KnowledgeCheckAttempt::count(), 'Une tentative refusée ne doit rien persister.');
    }

    public function test_un_quiz_sans_quota_reste_illimite(): void
    {
        $quiz = $this->quiz(maxAttempts: null);
        Sanctum::actingAs($this->student);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson("/api/knowledge-checks/{$quiz->id}/submit", $this->answers())
                ->assertStatus(200);
        }

        $this->assertSame(3, KnowledgeCheckAttempt::count());
        $this->assertSame(
            [1, 2, 3],
            KnowledgeCheckAttempt::orderBy('id')->pluck('attempt_number')->all(),
        );
    }

    // ───────────────────── R3.3 — course → 409, jamais 500 ─────────────────────

    /**
     * Course déterministe : une soumission concurrente s'intercale entre le
     * calcul du numéro de tentative et l'insertion. La base rejette l'insertion
     * perdante ; le service doit répondre en conflit métier, jamais en 500.
     */
    public function test_une_course_concurrente_donne_409_et_jamais_500(): void
    {
        $quiz = $this->quiz(maxAttempts: 5);
        Sanctum::actingAs($this->student);

        $this->insertCompetingAttemptOnNextCreate($quiz);

        $response = $this->postJson("/api/knowledge-checks/{$quiz->id}/submit", $this->answers());

        $response->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertSame(
            1,
            KnowledgeCheckAttempt::count(),
            'Seule la tentative gagnante de la course doit exister.',
        );
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private function persistAttempt(KnowledgeCheck $quiz, int $attemptNumber): void
    {
        KnowledgeCheckAttempt::create([
            'knowledge_check_id' => $quiz->id,
            'user_id' => $this->student->id,
            'institution_id' => $this->institution->id,
            'attempt_number' => $attemptNumber,
            'score' => 0,
            'correct_answers' => 0,
            'total_questions' => 2,
            'answers' => [],
            'time_spent_seconds' => 5,
            'passed' => false,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
        ]);
    }

    /**
     * Simule le concurrent : au moment précis où le service s'apprête à
     * insérer, une autre requête a déjà pris le même `attempt_number`.
     * Insertion via le query builder pour ne pas re-déclencher l'événement.
     */
    private function insertCompetingAttemptOnNextCreate(KnowledgeCheck $quiz): void
    {
        KnowledgeCheckAttempt::creating(function (KnowledgeCheckAttempt $attempt) use ($quiz): void {
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;

            DB::table('knowledge_check_attempts')->insert([
                'knowledge_check_id' => $quiz->id,
                'user_id' => $this->student->id,
                'institution_id' => $this->institution->id,
                'attempt_number' => $attempt->attempt_number,
                'score' => 0,
                'correct_answers' => 0,
                'total_questions' => 2,
                'answers' => '[]',
                'time_spent_seconds' => 5,
                'passed' => false,
                'started_at' => now(),
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
