<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Chapter;
use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\KnowledgeCheck;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #540 — la migration qui répare la RACINE de la famille « scope tenant contre
 * index unique » : `institution_id`, ajoutée nullable et jamais backfillée,
 * laissait toute ligne antérieure à février 2026 invisible au global scope mais
 * bien visible pour l'index unique.
 *
 * @see database/migrations/2026_08_24_090002_backfill_institution_id_on_attempt_tables.php
 */
final class AttemptInstitutionBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_24_090002_backfill_institution_id_on_attempt_tables.php';

    public function test_les_tentatives_heritees_recuperent_linstitution_de_leur_parent(): void
    {
        $institution = Institution::factory()->create();
        $teacher = User::factory()->teacher()->create(['institution_id' => $institution->id]);
        $student = User::factory()->student()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 4321,
        ]);

        $lesson = Lesson::factory()->create([
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'status' => 'published',
        ]);
        $quiz = Quiz::factory()->create([
            'institution_id' => $institution->id,
            'lesson_id' => $lesson->id,
            'status' => 'published',
            'published_at' => now()->subDay(),
        ]);
        $chapter = Chapter::factory()->create([
            'lesson_id' => $lesson->id,
            'institution_id' => $institution->id,
            'enseignant_id' => $teacher->id,
            'content_type' => 'quiz',
            'order' => 0,
        ]);
        $knowledgeCheck = KnowledgeCheck::factory()->create([
            'chapter_id' => $chapter->id,
            'institution_id' => $institution->id,
            'is_active' => true,
        ]);
        $evaluation = Evaluation::factory()->planifiee()->create(['institution_id' => $institution->id]);

        $quizAttempt = DB::table('quiz_attempts')->insertGetId([
            'quiz_id' => $quiz->id, 'user_id' => $student->id, 'attempt_number' => 1,
            'status' => 'graded', 'institution_id' => null,
            'started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $submission = DB::table('evaluation_submissions')->insertGetId([
            'evaluation_id' => $evaluation->id, 'klassci_etudiant_id' => 4321, 'attempt' => 1,
            'status' => 'soumis', 'institution_id' => null, 'synced_to_klassci' => false,
            'started_at' => now(), 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $kcAttempt = DB::table('knowledge_check_attempts')->insertGetId([
            'knowledge_check_id' => $knowledgeCheck->id, 'user_id' => $student->id,
            'attempt_number' => 1, 'score' => 50, 'correct_answers' => 1, 'total_questions' => 2,
            'answers' => '[]', 'time_spent_seconds' => 10, 'passed' => false, 'institution_id' => null,
            'started_at' => now(), 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->runMigrationUp();

        $this->assertSame(
            $institution->id,
            (int) DB::table('quiz_attempts')->where('id', $quizAttempt)->value('institution_id'),
        );
        $this->assertSame(
            $institution->id,
            (int) DB::table('evaluation_submissions')->where('id', $submission)->value('institution_id'),
        );
        $this->assertSame(
            $institution->id,
            (int) DB::table('knowledge_check_attempts')->where('id', $kcAttempt)->value('institution_id'),
        );
    }

    /**
     * Frontière multi-tenant : chaque tentative hérite de SON parent, jamais
     * d'un autre. Sans cette garantie, la réparation créerait la fuite
     * cross-tenant qu'elle prétend fermer.
     */
    public function test_chaque_tentative_herite_de_son_propre_parent(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();

        $evaluationA = Evaluation::factory()->planifiee()->create(['institution_id' => $institutionA->id]);
        $evaluationB = Evaluation::factory()->planifiee()->create(['institution_id' => $institutionB->id]);

        $rowA = $this->insertOrphanSubmission($evaluationA->id, 111);
        $rowB = $this->insertOrphanSubmission($evaluationB->id, 222);

        $this->runMigrationUp();

        $this->assertSame(
            $institutionA->id,
            (int) DB::table('evaluation_submissions')->where('id', $rowA)->value('institution_id'),
        );
        $this->assertSame(
            $institutionB->id,
            (int) DB::table('evaluation_submissions')->where('id', $rowB)->value('institution_id'),
        );
    }

    /** Un parent sans institution (contenu plateforme) laisse la ligne à NULL. */
    public function test_un_parent_sans_institution_laisse_la_tentative_intacte(): void
    {
        $evaluation = Evaluation::factory()->planifiee()->create(['institution_id' => null]);
        $orphan = $this->insertOrphanSubmission($evaluation->id, 999);

        $this->runMigrationUp();

        $this->assertNull(
            DB::table('evaluation_submissions')->where('id', $orphan)->value('institution_id'),
        );
    }

    private function runMigrationUp(): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION);
        $migration->up();
    }

    private function insertOrphanSubmission(int $evaluationId, int $klassciEtudiantId): int
    {
        return (int) DB::table('evaluation_submissions')->insertGetId([
            'evaluation_id' => $evaluationId,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'attempt' => 1,
            'status' => 'soumis',
            'institution_id' => null,
            'synced_to_klassci' => false,
            'started_at' => now(),
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
