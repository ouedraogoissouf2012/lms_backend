<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * #801 (PR 2) — `institution_id` devient obligatoire sur les tables déclarées.
 *
 * Les uniques composites par école ne mordent pas sur une ligne à NULL : en SQL, plusieurs
 * NULL sont admis dans un index unique. Mesuré en production le 23/11 : 0 NULL sur 29 tables
 * sur 30, sauf `notifications` (8 lignes, réparées à la source par #1515 et remplies ici).
 * `users` est hors de cette PR : le supradmin y garde NULL (PR 3).
 *
 * ⚠️ Sous MySQL, un DDL auto-commite : la transaction de `RefreshDatabase` ne peut plus rien
 * annuler. Les tests qui touchent au schéma le remettent dans un `finally`, et effacent leurs
 * lignes en `tearDown()` (patron de `SeanceMatiereIdMigrationTest`).
 */
final class InstitutionIdObligatoireTest extends TestCase
{
    use RefreshDatabase;

    private const REMPLISSAGE = '2026_11_23_140000_fill_institution_id_on_notifications.php';

    private const REMPLISSAGE_CLASSE_MATIERE = '2026_11_23_140050_fill_institution_id_on_classe_matiere.php';

    private const OBLIGATOIRE = '2026_11_23_140100_make_institution_id_required.php';

    protected function tearDown(): void
    {
        DB::table('notifications')->delete();
        DB::table('classe_matiere')->delete();
        DB::table('matieres')->delete();
        DB::table('classes')->delete();
        DB::table('users')->delete();
        DB::table('institutions')->delete();

        parent::tearDown();
    }

    /** L'invariant : toute table déclarée, sauf `users`, refuse un `institution_id` nul. */
    public function test_toute_table_declaree_sauf_users_exige_une_ecole(): void
    {
        $declarees = array_values(array_diff((array) config('tenancy.institution_scoped_tables'), ['users']));
        $nullables = array_values(array_filter($declarees, fn (string $table): bool => $this->nullable($table)));

        self::assertGreaterThanOrEqual(33, count($declarees), 'La garde ne lit pas la liste où elle croit.');
        self::assertSame([], $nullables, count($declarees).' tables déclarées hors users ; encore nullables :');
    }

    public function test_la_base_refuse_une_notification_sans_ecole(): void
    {
        $compte = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('notifications')->insert($this->notification($compte->id) + ['institution_id' => null]);
    }

    public function test_le_remplissage_rattache_une_notification_a_l_ecole_de_son_compte(): void
    {
        $enseignant = User::factory()->teacher()->create(['institution_id' => Institution::factory()->create()->id]);
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $this->avecColonneFacultative('notifications', function () use ($enseignant, $supradmin): void {
            $aRemplir = DB::table('notifications')->insertGetId($this->notification($enseignant->id));
            $sansParent = DB::table('notifications')->insertGetId($this->notification($supradmin->id));

            $this->migration(self::REMPLISSAGE)->up();

            self::assertSame($enseignant->institution_id, (int) DB::table('notifications')->where('id', $aRemplir)->value('institution_id'));
            self::assertNull(DB::table('notifications')->where('id', $sansParent)->value('institution_id'), 'Aucune école inventée.');
        });
    }

    /**
     * Une base qui a fait vivre une école AUTONOME porte des lignes `classe_matiere` sans école
     * (le défaut de `LocalClasseMatiereLinker`) : elles prennent l'école de leur classe.
     */
    public function test_le_remplissage_rattache_classe_matiere_a_l_ecole_de_sa_classe(): void
    {
        $classe = Classe::factory()->create();
        $matiere = Matiere::factory()->create(['institution_id' => $classe->institution_id]);

        $this->avecColonneFacultative('classe_matiere', function () use ($classe, $matiere): void {
            $ligne = DB::table('classe_matiere')->insertGetId([
                'classe_id' => $classe->id,
                'matiere_id' => $matiere->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->migration(self::REMPLISSAGE_CLASSE_MATIERE)->up();

            self::assertSame($classe->institution_id, (int) DB::table('classe_matiere')->where('id', $ligne)->value('institution_id'));
        });
    }

    public function test_la_garde_annule_avant_tout_ddl_et_ne_supprime_rien(): void
    {
        $supradmin = User::factory()->create(['institution_id' => null, 'role' => 'supradmin']);

        $this->avecColonneFacultative('notifications', function () use ($supradmin): void {
            DB::table('notifications')->insert($this->notification($supradmin->id));

            try {
                $this->migration(self::OBLIGATOIRE)->up();
                self::fail('La garde devait annuler la migration.');
            } catch (RuntimeException $annulation) {
                self::assertStringContainsString('notifications : 1 ligne(s)', $annulation->getMessage());
                self::assertStringContainsString('institutions:audit-orphans', $annulation->getMessage());
            }

            self::assertTrue($this->nullable('notifications'), 'Annulée AVANT tout DDL : rien n\'a changé.');
            self::assertSame(1, DB::table('notifications')->count(), 'Aucune ligne supprimée.');
        });
    }

    public function test_down_rend_la_colonne_facultative_et_up_la_remet(): void
    {
        try {
            $this->migration(self::OBLIGATOIRE)->down();
            self::assertTrue($this->nullable('notifications'));
            self::assertTrue($this->nullable('lessons'));
            self::assertFalse($this->nullable('consents'), 'Une table déjà obligatoire avant #801 le reste.');
        } finally {
            $this->migration(self::OBLIGATOIRE)->up();
        }

        self::assertFalse($this->nullable('notifications'));
        self::assertFalse($this->nullable('lessons'));
    }

    private function nullable(string $table): bool
    {
        foreach (Schema::getColumns($table) as $colonne) {
            if ($colonne['name'] === 'institution_id') {
                return (bool) $colonne['nullable'];
            }
        }

        self::fail("{$table} ne porte pas institution_id.");
    }

    /** Rend `$table.institution_id` facultative le temps du test, puis la remet. */
    private function avecColonneFacultative(string $table, callable $test): void
    {
        $this->changer($table, true);

        try {
            $test();
        } finally {
            DB::table($table)->delete();
            $this->changer($table, false);
        }
    }

    private function changer(string $table, bool $nullable): void
    {
        Schema::table($table, static function (Blueprint $colonnes) use ($nullable): void {
            $colonnes->unsignedBigInteger('institution_id')->nullable($nullable)->change();
        });
    }

    private function migration(string $fichier): Migration
    {
        $migration = require database_path('migrations/'.$fichier);
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /** @return array<string, mixed> */
    private function notification(int $compte): array
    {
        return [
            'user_id' => $compte,
            'type' => 'visio_starting',
            'title' => 'Visio',
            'message' => 'La visio a démarré.',
            'data' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
