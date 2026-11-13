<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Institution;
use App\Models\Seance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * La migration de `seances.matiere_id` (S-matière) : sa colonne LOCALE et son
 * index `(institution_id, matiere_id)`.
 *
 * - `up()` se rejoue sans erreur : sous MySQL, chaque DDL auto-commite, et une
 *   migration interrompue laisse un schéma à moitié posé ;
 * - `down()` REFUSE tant qu'une séance porte une matière locale : aucune colonne
 *   ne pourrait la garder ;
 * - sans donnée, `down()` retire l'index puis la colonne, et `up()` les remet.
 *
 * ⚠️ Sous MySQL, un DDL auto-commite : la transaction de `RefreshDatabase` ne peut
 * plus rien annuler ici. Le schéma est remis par `up()` en `finally`, et les
 * lignes sont effacées en `tearDown()`.
 */
final class SeanceMatiereIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_12_220000_add_matiere_id_to_seances_table.php';

    private const INDEX = 'seances_institution_matiere_locale_index';

    protected function tearDown(): void
    {
        foreach (['seances', 'institutions'] as $table) {
            DB::table($table)->delete();
        }

        parent::tearDown();
    }

    public function test_up_se_rejoue_sans_erreur(): void
    {
        $this->migration()->up();
        $this->migration()->up();

        self::assertTrue(Schema::hasColumn('seances', 'matiere_id'));
        self::assertTrue(Schema::hasIndex('seances', self::INDEX));
    }

    public function test_down_refuse_tant_qu_une_seance_porte_une_matiere_locale(): void
    {
        Seance::factory()->for(Institution::factory())->create(['matiere_id' => 7]);

        try {
            $this->migration()->down();
            self::fail('Le retour arrière devait refuser : une séance porte une matière locale.');
        } catch (RuntimeException $refus) {
            self::assertStringContainsString('1 séance(s)', $refus->getMessage());
        }

        self::assertTrue(Schema::hasColumn('seances', 'matiere_id'), 'Rien n’est retiré quand le retour est refusé.');
        self::assertTrue(Schema::hasIndex('seances', self::INDEX));
        self::assertSame(1, DB::table('seances')->whereNotNull('matiere_id')->count());
    }

    public function test_sans_donnee_down_retire_l_index_et_la_colonne_et_up_les_remet(): void
    {
        try {
            $this->migration()->down();

            self::assertFalse(Schema::hasIndex('seances', self::INDEX));
            self::assertFalse(Schema::hasColumn('seances', 'matiere_id'));
        } finally {
            $this->migration()->up();
        }

        self::assertTrue(Schema::hasColumn('seances', 'matiere_id'));
        self::assertTrue(Schema::hasIndex('seances', self::INDEX));
    }

    private function migration(): object
    {
        /** @var object $migration */
        $migration = require base_path('database/migrations/'.self::MIGRATION);

        return $migration;
    }
}
