<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * C28 — la migration de l'échéance et du verrou se rejoue sans erreur (revue de
 * Fille 1, O2), sur le patron de `SeanceMatiereIdMigrationTest`.
 *
 * Avant : `up()` ajoutait `ends_at` sans vérifier qu'elle existait ; un second
 * passage échouait sur une colonne en double.
 */
final class CopiesEcheanceEtVerrouMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_13_210000_copies_echeance_et_verrou.php';

    protected function tearDown(): void
    {
        foreach (['evaluation_submissions', 'evaluation_questions', 'evaluations', 'users', 'institutions'] as $table) {
            DB::table($table)->delete();
        }

        parent::tearDown();
    }

    public function test_up_se_rejoue_sans_erreur(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        self::assertTrue(Schema::hasColumn('evaluation_submissions', 'ends_at'));
        $this->assertLeStatutVerrouilleSEcrit();
    }

    public function test_down_retire_l_echeance_et_rend_les_copies_verrouillees_a_en_cours_puis_up_les_remet(): void
    {
        $copie = EvaluationSubmission::factory()->for(Evaluation::factory())->create(['status' => 'verrouille']);

        try {
            $this->migration()->down();
            $this->migration()->down();

            self::assertFalse(Schema::hasColumn('evaluation_submissions', 'ends_at'));
            self::assertSame('en_cours', DB::table('evaluation_submissions')->where('id', $copie->getKey())->value('status'));
        } finally {
            $this->migration()->up();
        }

        self::assertTrue(Schema::hasColumn('evaluation_submissions', 'ends_at'));
        $this->assertLeStatutVerrouilleSEcrit();
    }

    private function assertLeStatutVerrouilleSEcrit(): void
    {
        $copie = EvaluationSubmission::factory()->for(Evaluation::factory())->create(['status' => 'en_cours']);
        DB::table('evaluation_submissions')->where('id', $copie->getKey())->update(['status' => 'verrouille']);

        self::assertSame('verrouille', DB::table('evaluation_submissions')->where('id', $copie->getKey())->value('status'));
    }

    private function migration(): object
    {
        /** @var object $migration */
        $migration = require base_path('database/migrations/'.self::MIGRATION);

        return $migration;
    }
}
