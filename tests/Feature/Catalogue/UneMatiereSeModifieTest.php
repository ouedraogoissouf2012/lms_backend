<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Enums\InstitutionMode;
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
 * #1653 (PR A) — une matière LOCALE se modifie, et son code est unique dans son école.
 *
 * L'unicité ne vise que les matières LOCALES (`klassci_id` nul) : les codes des matières miroir
 * viennent de KLASSCI, ce n'est pas au LMS de les dédoublonner. Elle est garantie en base par une
 * colonne générée (`code_local`) et un unique `(institution_id, code_local)`, et rendue lisible en
 * 422 `subject_code_already_used` à la création comme à la modification. La casse n'y compte pas,
 * sur les deux moteurs. Un code vide n'est pas un code : il devient NULL.
 *
 * Vrai jeton (`asTenant`), les deux volets.
 *
 * ⚠️ Le test de la garde rejoue `down()` puis `up()` : sous MySQL, un DDL valide la transaction
 * du test. Ses lignes sont donc effacées par lui-même, en `finally`.
 */
final class UneMatiereSeModifieTest extends TestCase
{
    use ActsAsTenantUser;
    use RefreshDatabase;

    private const MIGRATION = '2026_11_27_100000_code_de_matiere_unique_par_ecole.php';

    public function test_une_matiere_locale_se_modifie(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $matiere = $this->matiere($ecole, 'BUR-1');

        $this->asTenant($gestion)->patchJson("/api/matieres/{$matiere->id}", [
            'libelle' => 'Traitement de texte avancé', 'code' => 'BUR-2', 'description' => 'Styles et sommaire',
            'coefficient' => 3, 'credit' => 4,
        ])->assertOk()
            ->assertJsonPath('data.libelle', 'Traitement de texte avancé')
            ->assertJsonPath('data.code', 'BUR-2')
            ->assertJsonPath('data.coefficient', 3)
            ->assertJsonPath('data.credit', 4);
    }

    public function test_un_champ_absent_garde_sa_valeur(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $matiere = $this->matiere($ecole, 'BUR-1');

        $this->asTenant($gestion)->patchJson("/api/matieres/{$matiere->id}", ['coefficient' => 5])->assertOk()
            ->assertJsonPath('data.code', 'BUR-1')
            ->assertJsonPath('data.libelle', $matiere->libelle);
    }

    public function test_un_code_deja_pris_dans_l_ecole_est_refuse_a_la_creation_et_a_la_modification(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $this->matiere($ecole, 'BUR-1');
        $autre = $this->matiere($ecole, 'BUR-2');

        $this->asTenant($gestion)->postJson('/api/matieres', ['libelle' => 'Doublon', 'code' => 'BUR-1'])
            ->assertStatus(422)->assertJsonValidationErrors(['code'])
            ->assertJsonPath('reason', 'subject_code_already_used');
        // Ni la casse ni les blancs ne font un autre code, sur MySQL comme sur SQLite.
        $this->asTenant($gestion)->patchJson("/api/matieres/{$autre->id}", ['code' => '  bur-1 '])
            ->assertStatus(422)->assertJsonValidationErrors(['code'])
            ->assertJsonPath('reason', 'subject_code_already_used');
        self::assertSame('BUR-2', $autre->fresh()?->code);
    }

    public function test_garder_son_propre_code_n_est_pas_un_doublon(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $matiere = $this->matiere($ecole, 'BUR-1');

        $this->asTenant($gestion)->patchJson("/api/matieres/{$matiere->id}", ['code' => 'BUR-1', 'libelle' => 'Renommée'])->assertOk();
    }

    public function test_le_meme_code_vit_dans_une_autre_ecole_et_a_cote_d_une_matiere_miroir(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $this->matiere(Institution::factory()->create(['mode' => InstitutionMode::Standalone]), 'BUR-1');
        // Une matière miroir de KLASSCI au même code : son code n'est pas au LMS.
        Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4242, 'code' => 'BUR-1']);

        $this->asTenant($gestion)->postJson('/api/matieres', ['libelle' => 'Bureautique', 'code' => 'BUR-1'])->assertCreated();
    }

    public function test_deux_codes_vides_ne_sont_pas_un_doublon(): void
    {
        [$gestion] = $this->gestion();

        $this->asTenant($gestion)->postJson('/api/matieres', ['libelle' => 'Sans code A', 'code' => ''])->assertCreated()
            ->assertJsonPath('data.code', null);
        $this->asTenant($gestion)->postJson('/api/matieres', ['libelle' => 'Sans code B', 'code' => ''])->assertCreated();
    }

    public function test_la_base_refuse_deux_matieres_locales_au_meme_code(): void
    {
        [, $ecole] = $this->gestion();
        $this->matiere($ecole, 'BUR-1');

        $this->expectException(QueryException::class);

        DB::table('matieres')->insert(['institution_id' => $ecole->id, 'libelle' => 'Doublon', 'code' => 'BUR-1', 'klassci_id' => null, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_une_matiere_miroir_ne_se_modifie_pas_ici(): void
    {
        [$gestion, $ecole] = $this->gestion();
        $miroir = Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => 4242, 'code' => 'K-1']);

        $this->asTenant($gestion)->patchJson("/api/matieres/{$miroir->id}", ['libelle' => 'Renommée'])
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    public function test_une_matiere_d_une_autre_ecole_est_introuvable(): void
    {
        [$gestion] = $this->gestion();
        $ailleurs = $this->matiere(Institution::factory()->create(['mode' => InstitutionMode::Standalone]), 'BUR-9');

        $this->asTenant($gestion)->patchJson("/api/matieres/{$ailleurs->id}", ['libelle' => 'Intrusion'])->assertNotFound();
        self::assertNotSame('Intrusion', $ailleurs->fresh()?->libelle);
    }

    public function test_une_ecole_klassci_est_refusee(): void
    {
        [$gestion, $ecole] = $this->gestion(InstitutionMode::Klassci);
        $matiere = $this->matiere($ecole, 'BUR-1');

        $this->asTenant($gestion)->patchJson("/api/matieres/{$matiere->id}", ['libelle' => 'Renommée'])
            ->assertForbidden()->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    public function test_la_garde_annule_avant_tout_ddl_devant_un_doublon_local(): void
    {
        $ecole = Institution::factory()->create(['mode' => InstitutionMode::Standalone]);

        try {
            $this->migration()->down();
            $this->matiere($ecole, 'BUR-1');
            $this->matiere($ecole, 'bur-1');

            try {
                $this->migration()->up();
                self::fail('La garde devait annuler la migration.');
            } catch (RuntimeException $annulation) {
                self::assertStringContainsString('1 code(s) en double parmi les matières locales', $annulation->getMessage());
                self::assertStringContainsString("école {$ecole->id}, code « BUR-1 » (2 fois)", $annulation->getMessage());
            }
            // Rien n'a été touché : ni la colonne, ni l'unique.
            self::assertFalse(Schema::hasColumn('matieres', 'code_local'));
        } finally {
            DB::table('matieres')->where('institution_id', $ecole->id)->delete();
            $this->migration()->up();
            DB::table('institutions')->where('id', $ecole->id)->delete();
        }
    }

    /** @return array{0: User, 1: Institution} */
    private function gestion(InstitutionMode $mode = InstitutionMode::Standalone): array
    {
        $ecole = Institution::factory()->create(['mode' => $mode]);

        return [User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]), $ecole];
    }

    private function matiere(Institution $ecole, string $code): Matiere
    {
        return Matiere::factory()->create(['institution_id' => $ecole->id, 'klassci_id' => null, 'code' => $code]);
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
