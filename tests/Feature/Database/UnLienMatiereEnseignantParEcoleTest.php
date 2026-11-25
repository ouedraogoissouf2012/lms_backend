<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Enums\InstitutionMode;
use App\Models\Classe;
use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\TestCase;

/**
 * #801 (PR 4) — un lien enseignant ↔ matière KLASSCI est unique DANS son école.
 *
 * L'ancien unique `(klassci_matiere_id, klassci_enseignant_id, annee_universitaire_id)` ne
 * dédoublonnait rien : le seul écrivain (`TeacherMatieresLinker`) laisse l'année à NULL, et SQL
 * ne dédoublonne pas les NULL. Il ignorait aussi l'école : deux écoles KLASSCI (des instances
 * distinctes, aux identifiants qui se recouvrent) se seraient bloquées. Le nouvel unique est la
 * clé de rapprochement explicite du linker : `(institution_id, klassci_matiere_id,
 * klassci_enseignant_id)`.
 *
 * Les témoins du linker (idempotence, isolement par école, rien sans identité KLASSCI) sont dans
 * `TeacherMatieresLinkerTest`.
 *
 * ⚠️ Les tests de la garde et de `down()` rejouent un DDL : sous MySQL, il auto-commite. Les
 * lignes sont effacées en `tearDown()`.
 */
final class UnLienMatiereEnseignantParEcoleTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MIGRATION = '2026_11_24_120000_unique_matiere_enseignant_par_ecole.php';

    private const NOUVEL_UNIQUE = 'matiere_enseignant_ecole_matiere_enseignant_unique';

    private const ANCIEN_UNIQUE = 'unique_matiere_enseignant_annee';

    protected function tearDown(): void
    {
        foreach (['matiere_enseignant', 'classe_matiere', 'matieres', 'classes', 'users', 'institutions'] as $table) {
            DB::table($table)->delete();
        }

        parent::tearDown();
    }

    public function test_la_base_refuse_un_doublon_dans_la_meme_ecole(): void
    {
        $ecole = Institution::factory()->create();
        DB::table('matiere_enseignant')->insert($this->lien($ecole->id));

        $this->expectException(QueryException::class);

        DB::table('matiere_enseignant')->insert($this->lien($ecole->id));
    }

    /** L'ancien unique, sans l'école, bloquait la seconde école dès que l'année était posée. */
    public function test_deux_ecoles_gardent_chacune_leur_lien_aux_memes_identifiants(): void
    {
        $premiere = Institution::factory()->create();
        $seconde = Institution::factory()->create();

        DB::table('matiere_enseignant')->insert($this->lien($premiere->id, 2026));
        DB::table('matiere_enseignant')->insert($this->lien($seconde->id, 2026));

        self::assertSame(2, DB::table('matiere_enseignant')->count());
    }

    public function test_le_nouvel_unique_remplace_l_ancien(): void
    {
        self::assertTrue($this->unique(self::NOUVEL_UNIQUE));
        self::assertFalse($this->unique(self::ANCIEN_UNIQUE));
    }

    public function test_down_remet_l_ancien_unique_et_up_le_remplace(): void
    {
        try {
            $this->migration()->down();
            self::assertFalse($this->unique(self::NOUVEL_UNIQUE));
            self::assertTrue($this->unique(self::ANCIEN_UNIQUE));
        } finally {
            $this->migration()->up();
        }

        self::assertTrue($this->unique(self::NOUVEL_UNIQUE));
        self::assertFalse($this->unique(self::ANCIEN_UNIQUE));
    }

    public function test_la_garde_annule_avant_tout_ddl_et_ne_supprime_rien(): void
    {
        $ecole = Institution::factory()->create();

        try {
            $this->migration()->down();
            DB::table('matiere_enseignant')->insert($this->lien($ecole->id));
            DB::table('matiere_enseignant')->insert($this->lien($ecole->id));

            try {
                $this->migration()->up();
                self::fail('La garde devait annuler la migration devant un doublon.');
            } catch (RuntimeException $annulation) {
                self::assertStringContainsString('matiere_enseignant', $annulation->getMessage());
                self::assertStringContainsString('1 lien(s) en double', $annulation->getMessage());
            }

            self::assertSame(2, DB::table('matiere_enseignant')->count(), 'La garde ne supprime rien.');
            self::assertFalse($this->unique(self::NOUVEL_UNIQUE), 'Aucun DDL avant la garde.');
        } finally {
            DB::table('matiere_enseignant')->delete();
            $this->migration()->up();
        }
    }

    /** Témoin, volet autonome : rattacher une matière à une classe n'écrit pas le miroir KLASSCI. */
    public function test_une_ecole_autonome_n_ecrit_rien_dans_le_miroir_klassci(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);
        $gestionnaire = User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin']);
        $classe = Classe::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);
        $matiere = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null]);

        $this->asTenant($gestionnaire)->postJson("/api/classes/{$classe->id}/matieres", ['matiere_id' => $matiere->id])
            ->assertSuccessful();

        self::assertSame(1, DB::table('classe_matiere')->where('classe_id', $classe->id)->count());
        self::assertSame(0, DB::table('matiere_enseignant')->count());
    }

    /** @return array<string, mixed> */
    private function lien(int $ecole, ?int $annee = null): array
    {
        return [
            'institution_id' => $ecole,
            'klassci_matiere_id' => 31,
            'klassci_enseignant_id' => 42,
            'annee_universitaire_id' => $annee,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function unique(string $nom): bool
    {
        foreach (Schema::getIndexes('matiere_enseignant') as $index) {
            if ($index['name'] === $nom) {
                return $index['unique'];
            }
        }

        return false;
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
