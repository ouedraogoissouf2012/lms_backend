<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Chapter;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * #540 — vérifie le BACKFILL de la migration
 * `add_attempt_number_to_knowledge_check_attempts_table`, pas seulement son
 * état final.
 *
 * Une migration qui pose un index unique sur une colonne mal remplie échoue en
 * production sur des données réelles alors qu'elle passe sur une base neuve.
 * Le test remet donc la table dans son état d'AVANT migration (colonne et index
 * retirés), y insère des lignes héritées, puis rejoue `up()`.
 *
 * @see database/migrations/2026_08_24_090000_add_attempt_number_to_knowledge_check_attempts_table.php
 */
final class KnowledgeCheckAttemptNumberBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_24_090000_add_attempt_number_to_knowledge_check_attempts_table.php';

    public function test_le_backfill_numerote_les_tentatives_heritees_par_couple_quiz_etudiant(): void
    {
        [$quizA, $quizB, $studentA, $studentB, $institution] = $this->fixtures();

        $this->revertToPreMigrationState();

        // Ordre d'insertion volontairement entrelacé entre les couples : le
        // backfill doit numéroter PAR couple, pas dans l'ordre global des id.
        $rows = [
            [$quizA->id, $studentA->id],
            [$quizB->id, $studentA->id],
            [$quizA->id, $studentA->id],
            [$quizA->id, $studentB->id],
            [$quizA->id, $studentA->id],
            [$quizB->id, $studentA->id],
        ];
        $ids = [];
        foreach ($rows as [$quizId, $userId]) {
            $ids[] = $this->insertLegacyAttempt($quizId, $userId, $institution->id);
        }

        $this->runMigrationUp();

        $numbers = DB::table('knowledge_check_attempts')
            ->orderBy('id')
            ->pluck('attempt_number', 'id');

        // quizA/studentA : 1, 2, 3 — quizB/studentA : 1, 2 — quizA/studentB : 1
        $this->assertSame(1, (int) $numbers[$ids[0]]);
        $this->assertSame(1, (int) $numbers[$ids[1]]);
        $this->assertSame(2, (int) $numbers[$ids[2]]);
        $this->assertSame(1, (int) $numbers[$ids[3]]);
        $this->assertSame(3, (int) $numbers[$ids[4]]);
        $this->assertSame(2, (int) $numbers[$ids[5]]);
    }

    public function test_le_backfill_permet_la_pose_de_lunique_sans_collision(): void
    {
        [$quiz, , $student, , $institution] = $this->fixtures();

        $this->revertToPreMigrationState();

        for ($i = 0; $i < 4; $i++) {
            $this->insertLegacyAttempt($quiz->id, $student->id, $institution->id);
        }

        // Si le backfill laissait la valeur par défaut (1) partout, la pose de
        // l'unique lèverait une violation : ce test échoue alors bruyamment.
        $this->runMigrationUp();

        $uniqueExists = collect(Schema::getIndexes('knowledge_check_attempts'))
            ->contains(static fn (array $index): bool => $index['unique'] === true
                && count($index['columns']) === 3);

        $this->assertTrue($uniqueExists);
        $this->assertSame(
            [1, 2, 3, 4],
            DB::table('knowledge_check_attempts')->orderBy('id')
                ->pluck('attempt_number')->map(static fn ($n): int => (int) $n)->all(),
        );
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    /**
     * @return array{0: KnowledgeCheck, 1: KnowledgeCheck, 2: User, 3: User, 4: Institution}
     */
    private function fixtures(): array
    {
        $institution = Institution::factory()->create();
        $teacher = User::factory()->create(['institution_id' => $institution->id, 'role' => 'enseignant']);
        $lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'status' => 'published',
        ]);
        $chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);

        $quizAttributes = [
            'chapter_id' => $chapter->id,
            'institution_id' => $institution->id,
            'is_active' => true,
            'max_attempts' => null,
        ];

        return [
            KnowledgeCheck::factory()->create($quizAttributes),
            KnowledgeCheck::factory()->create($quizAttributes),
            User::factory()->create(['institution_id' => $institution->id, 'role' => 'etudiant']),
            User::factory()->create(['institution_id' => $institution->id, 'role' => 'etudiant']),
            $institution,
        ];
    }

    /** Remet la table dans son état d'avant la migration testée. */
    private function revertToPreMigrationState(): void
    {
        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->dropUnique('kc_attempts_user_attempt_unique');
        });
        Schema::table('knowledge_check_attempts', function (Blueprint $table): void {
            $table->dropColumn('attempt_number');
        });
    }

    private function runMigrationUp(): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION);
        $migration->up();
    }

    private function insertLegacyAttempt(int $quizId, int $userId, int $institutionId): int
    {
        return (int) DB::table('knowledge_check_attempts')->insertGetId([
            'knowledge_check_id' => $quizId,
            'user_id' => $userId,
            'institution_id' => $institutionId,
            'score' => 50,
            'correct_answers' => 1,
            'total_questions' => 2,
            'answers' => '[]',
            'time_spent_seconds' => 10,
            'passed' => false,
            'started_at' => now(),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
