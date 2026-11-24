<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Monolog\Level;
use PDOException;
use Tests\Concerns\ActsAsTenantUser;
use Tests\Concerns\CapturesWrittenLog;
use Tests\TestCase;

/**
 * #1521 : un code de classe déjà employé dans l'établissement est une saisie invalide (422,
 * motif `class_code_already_used` sur `code`), pas une panne.
 *
 * Avant : `LocalClasseCreator` laissait passer la violation de l'unique `(institution_id, code)`,
 * que le rendu générique changeait en 500 et que le rapporteur journalisait comme une panne SQL.
 * La base tranche toujours, sans SELECT préalable : deux requêtes concurrentes restent
 * départagées par l'unique.
 */
final class CodeDeClasseDejaEmployeTest extends TestCase
{
    use ActsAsTenantUser;
    use CapturesWrittenLog;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->captureWrittenLog();
    }

    public function test_le_meme_code_deux_fois_est_une_saisie_invalide_sans_journal_d_erreur(): void
    {
        $responsable = $this->responsable(Institution::factory()->standalone()->create());

        $this->asTenant($responsable)
            ->postJson('/api/classes', ['libelle' => 'Groupe A', 'code' => 'BUR-A'])
            ->assertCreated();
        $this->asTenant($responsable)
            ->postJson('/api/classes', ['libelle' => 'Un autre groupe A', 'code' => 'BUR-A'])
            ->assertStatus(422)
            ->assertJsonPath('reason', 'class_code_already_used')
            ->assertJsonValidationErrors('code');

        self::assertSame(1, DB::table('classes')->where('code', 'BUR-A')->count());
        self::assertSame([], $this->recordsAtLeast(Level::Error), 'Une saisie invalide n\'est pas une panne.');
    }

    /** Verrou : l'unique est par établissement, deux écoles nomment librement « BUR-A ». */
    public function test_une_autre_ecole_peut_employer_le_meme_code(): void
    {
        foreach ([1, 2] as $_) {
            $this->asTenant($this->responsable(Institution::factory()->standalone()->create()))
                ->postJson('/api/classes', ['libelle' => 'Groupe A', 'code' => 'BUR-A'])
                ->assertCreated();
        }

        self::assertSame(2, DB::table('classes')->where('code', 'BUR-A')->count());
    }

    /** Verrou : sans code, deux classes ne collisionnent pas (le code vide devient `NULL`). */
    public function test_deux_classes_sans_code_sont_creees(): void
    {
        $responsable = $this->responsable(Institution::factory()->standalone()->create());

        foreach (['Groupe A', 'Groupe B'] as $libelle) {
            $this->asTenant($responsable)->postJson('/api/classes', ['libelle' => $libelle, 'code' => ''])->assertCreated();
        }
    }

    /**
     * Verrou : SEULE la violation d'unique devient un 422. Toute autre erreur SQL à l'écriture
     * reste une panne, un 500, journalisée.
     */
    public function test_une_autre_erreur_sql_a_l_ecriture_reste_une_panne(): void
    {
        $responsable = $this->responsable(Institution::factory()->standalone()->create());
        DB::connection()->beforeExecuting(static function (string $sql, array $liaisons): void {
            if (str_starts_with(strtolower($sql), 'insert into') && str_contains($sql, 'classes')) {
                throw new QueryException('sqlite', $sql, $liaisons, new PDOException('disque plein'));
            }
        });

        $this->asTenant($responsable)
            ->postJson('/api/classes', ['libelle' => 'Groupe A', 'code' => 'BUR-A'])
            ->assertStatus(500);
    }

    /** Verrou : une école KLASSCI ne crée pas de classe, refus inchangé. */
    public function test_ecole_klassci_le_catalogue_reste_refuse(): void
    {
        $this->asTenant($this->responsable(Institution::factory()->create()))
            ->postJson('/api/classes', ['libelle' => 'Groupe A', 'code' => 'BUR-A'])
            ->assertForbidden()
            ->assertJsonPath('reason', 'catalogue_managed_by_klassci');
    }

    private function responsable(Institution $ecole): User
    {
        // Sans jeton KLASSCI : aucune re-synchronisation ne s'invite ici.
        return User::factory()->create(['institution_id' => $ecole->id, 'role' => 'superAdmin', 'klassci_token' => null]);
    }
}
