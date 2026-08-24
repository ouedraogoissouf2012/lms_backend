<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Evaluation;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * #540 — vérifie la migration de réparation `student_id` sur les soumissions
 * historiques créées par `POST /start` (qui ne renseignait que
 * `klassci_etudiant_id`).
 *
 * @see database/migrations/2026_08_24_090001_backfill_student_id_on_evaluation_submissions.php
 */
final class EvaluationSubmissionStudentIdBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_08_24_090001_backfill_student_id_on_evaluation_submissions.php';

    public function test_les_soumissions_orphelines_sont_rattachees_a_leur_etudiant_local(): void
    {
        $institution = Institution::factory()->create();
        $student = User::factory()->student()->create([
            'institution_id' => $institution->id,
            'klassci_id' => 4242,
        ]);
        $evaluation = Evaluation::factory()->planifiee()->create(['institution_id' => $institution->id]);

        $orphan = $this->insertOrphanSubmission($evaluation->id, $institution->id, 4242, attempt: 1);

        $this->runMigrationUp();

        $this->assertSame(
            $student->id,
            (int) DB::table('evaluation_submissions')->where('id', $orphan)->value('student_id'),
        );
    }

    /**
     * Deux comptes portent le même `klassci_id` dans des institutions
     * différentes : le rattachement doit se faire dans la BONNE institution,
     * jamais au premier venu (garde-fou multi-tenant).
     */
    public function test_le_rattachement_respecte_la_frontiere_dinstitution(): void
    {
        $institutionA = Institution::factory()->create();
        $institutionB = Institution::factory()->create();

        User::factory()->student()->create(['institution_id' => $institutionA->id, 'klassci_id' => 777]);
        $studentB = User::factory()->student()->create(['institution_id' => $institutionB->id, 'klassci_id' => 777]);

        $evaluationB = Evaluation::factory()->planifiee()->create(['institution_id' => $institutionB->id]);
        $orphan = $this->insertOrphanSubmission($evaluationB->id, $institutionB->id, 777, attempt: 1);

        $this->runMigrationUp();

        $this->assertSame(
            $studentB->id,
            (int) DB::table('evaluation_submissions')->where('id', $orphan)->value('student_id'),
        );
    }

    /**
     * Aucun compte local ne porte ce `klassci_id` : la ligne reste à NULL.
     * Mieux vaut une donnée absente qu'une note rattachée au mauvais étudiant.
     */
    public function test_une_soumission_sans_etudiant_identifiable_reste_intacte(): void
    {
        $institution = Institution::factory()->create();
        $evaluation = Evaluation::factory()->planifiee()->create(['institution_id' => $institution->id]);

        $orphan = $this->insertOrphanSubmission($evaluation->id, $institution->id, 999999, attempt: 1);

        $this->runMigrationUp();

        $this->assertNull(DB::table('evaluation_submissions')->where('id', $orphan)->value('student_id'));
    }

    /**
     * Garde anti-régression du saut d'offset.
     *
     * La première version de la migration paginait en `chunk()` sur un prédicat
     * `whereNull('student_id')` que son propre callback modifiait : chaque
     * ligne réparée quittait le jeu de résultats et l'offset sautait d'autant.
     * Un test à un seul couple ne pouvait pas le voir. Ici, le nombre de
     * couples dépasse la taille de lot de la migration : si le parcours saute
     * ne serait-ce qu'une ligne, l'assertion « aucune orpheline » tombe.
     *
     * Volontairement piloté par le nombre de LIGNES et de COUPLES distincts,
     * pas par un seul étudiant : c'est la combinaison des deux qui exposait le
     * défaut.
     */
    public function test_toutes_les_orphelines_sont_reparees_au_dela_dun_lot(): void
    {
        $institution = Institution::factory()->create();
        $evaluation = Evaluation::factory()->planifiee()->create(['institution_id' => $institution->id]);

        $expected = [];
        for ($i = 0; $i < 40; $i++) {
            $klassciId = 10_000 + $i;
            $student = User::factory()->student()->create([
                'institution_id' => $institution->id,
                'klassci_id' => $klassciId,
            ]);
            $expected[$this->insertOrphanSubmission(
                $evaluation->id,
                $institution->id,
                $klassciId,
                attempt: 1,
            )] = $student->id;
        }

        $this->runMigrationUp();

        $this->assertSame(
            0,
            DB::table('evaluation_submissions')->whereNull('student_id')->count(),
            'Des soumissions orphelines subsistent : le parcours de la migration en a sauté.',
        );
        foreach ($expected as $submissionId => $studentId) {
            $this->assertSame(
                $studentId,
                (int) DB::table('evaluation_submissions')->where('id', $submissionId)->value('student_id'),
            );
        }
    }

    /** Une soumission déjà rattachée n'est pas ré-attribuée. */
    public function test_une_soumission_deja_rattachee_nest_pas_modifiee(): void
    {
        $institution = Institution::factory()->create();
        $owner = User::factory()->student()->create(['institution_id' => $institution->id, 'klassci_id' => 111]);
        $other = User::factory()->student()->create(['institution_id' => $institution->id, 'klassci_id' => 222]);
        $evaluation = Evaluation::factory()->planifiee()->create(['institution_id' => $institution->id]);

        // Ligne incohérente à dessein : klassci_id de `other`, student_id de `owner`.
        $id = $this->insertOrphanSubmission($evaluation->id, $institution->id, 222, attempt: 1, studentId: $owner->id);

        $this->runMigrationUp();

        $this->assertSame(
            $owner->id,
            (int) DB::table('evaluation_submissions')->where('id', $id)->value('student_id'),
        );
        $this->assertNotSame(
            $other->id,
            (int) DB::table('evaluation_submissions')->where('id', $id)->value('student_id'),
        );
    }

    // ───────────────────────────── Helpers ─────────────────────────────

    private function runMigrationUp(): void
    {
        $migration = require database_path('migrations/' . self::MIGRATION);
        $migration->up();
    }

    private function insertOrphanSubmission(
        int $evaluationId,
        int $institutionId,
        int $klassciEtudiantId,
        int $attempt,
        ?int $studentId = null,
    ): int {
        return (int) DB::table('evaluation_submissions')->insertGetId([
            'evaluation_id' => $evaluationId,
            'student_id' => $studentId,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'institution_id' => $institutionId,
            'attempt' => $attempt,
            'status' => 'soumis',
            'started_at' => now()->subHour(),
            'submitted_at' => now(),
            'synced_to_klassci' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
