<?php

declare(strict_types=1);

namespace Tests\Feature\Retention;

use App\Models\Institution;
use App\Models\Matiere;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * #719 — la purge d'une école ne peut plus échouer sur une contrainte, et elle laisse un
 * journal qui lui survit.
 *
 * ## B7, version sûre
 *
 * `SoftDeletedInstitutionsPolicy::refuses()` ne regardait que quatre relations (users,
 * classes, lessons, evaluations). Les 34 tables déclarées portent pourtant une clé
 * `institution_id` en RESTRICT : une école vide de ces quatre-là mais peuplée ailleurs
 * faisait lever une exception de contrainte au `forceDelete()`. Désormais, toutes les tables
 * déclarées sont lues, et la purge REFUSE en nommant ce qui reste. Détruire une école peuplée
 * est une autre décision (#1487).
 *
 * Tout se passe SANS école résolue : le scope tenant masquerait un filtre `institution_id`
 * manquant (le refus serait juste par accident).
 *
 * ## B8
 *
 * `purge_log` garde une ligne par élément purgé : un identifiant, jamais un contenu. Sa clé
 * vers `institutions` est en SET NULL : le journal survit à la purge de son sujet. En RESTRICT,
 * purger une école serait bloqué par son propre journal.
 */
final class LaPurgeDUneEcoleEstSureTest extends TestCase
{
    use RefreshDatabase;

    /** @return iterable<string, array{string}> */
    public static function tablesHorsDesQuatreAnciennesRelations(): iterable
    {
        yield 'matieres' => ['matieres'];
        yield 'consents' => ['consents'];
    }

    #[DataProvider('tablesHorsDesQuatreAnciennesRelations')]
    public function test_une_ecole_encore_peuplee_est_epargnee_en_nommant_la_table(string $table): void
    {
        $ecole = $this->ecoleSupprimeeIlYA(90);
        $this->peupler($table, $ecole);

        $this->artisan('purge:run', ['domaine' => 'institutions', '--force' => true])
            ->expectsOutputToContain($table)
            ->assertExitCode(0);

        self::assertNotNull(Institution::withTrashed()->find($ecole->id), 'L\'école peuplée doit rester.');
        self::assertSame(0, DB::table('purge_log')->count(), 'Un refus n\'est pas une purge : rien au journal.');
    }

    public function test_une_ecole_vide_est_purgee_et_son_journal_lui_survit(): void
    {
        $ecole = $this->ecoleSupprimeeIlYA(90);

        $this->artisan('purge:run', ['domaine' => 'institutions', '--force' => true])->assertExitCode(0);

        self::assertNull(Institution::withTrashed()->find($ecole->id));
        $journal = DB::table('purge_log')->get();
        self::assertCount(1, $journal);
        $ligne = (array) $journal->first();
        self::assertNull($ligne['institution_id'], 'SET NULL : le sujet a disparu, la ligne reste.');
        self::assertSame((string) $ecole->id, (string) $ligne['subject_key']);
        self::assertSame('institutions', $ligne['subject_type']);
        self::assertSame('institutions', $ligne['category']);
        self::assertSame(1, (int) $ligne['rows_deleted']);
        self::assertNotEmpty($ligne['executed_at']);
        self::assertNotEmpty($ligne['retention_rule']);
    }

    public function test_aucune_donnee_personnelle_n_entre_au_journal(): void
    {
        $temoin = User::factory()->create(['email' => 'temoin.719@exemple.invalid', 'name' => 'Temoin Sept Cent Dix Neuf']);
        $temoin->delete();
        $temoin->forceFill(['deleted_at' => now()->subDays(90)])->saveQuietly();

        $this->artisan('purge:run', ['domaine' => 'users', '--force' => true])->assertExitCode(0);

        $journal = json_encode(DB::table('purge_log')->get()->all(), JSON_THROW_ON_ERROR);
        self::assertSame(1, DB::table('purge_log')->where('subject_key', (string) $temoin->id)->count());
        self::assertStringNotContainsString('temoin.719', $journal);
        self::assertStringNotContainsString('Temoin', $journal);
    }

    public function test_une_simulation_n_ecrit_rien_au_journal(): void
    {
        $this->ecoleSupprimeeIlYA(90);

        $this->artisan('purge:run', ['domaine' => 'institutions'])->assertExitCode(0);

        self::assertSame(0, DB::table('purge_log')->count());
    }

    public function test_le_journal_ne_retient_pas_son_ecole(): void
    {
        $cles = array_values(array_filter(
            Schema::getForeignKeys('purge_log'),
            static fn (array $cle): bool => $cle['columns'] === ['institution_id'],
        ));

        self::assertCount(1, $cles, 'purge_log.institution_id doit pointer vers institutions.');
        self::assertSame('institutions', $cles[0]['foreign_table']);
        self::assertSame('set null', strtolower((string) $cles[0]['on_delete']));
        self::assertArrayHasKey('purge_log', (array) config('tenancy.institution_scoped_exceptions'));
    }

    private function ecoleSupprimeeIlYA(int $jours): Institution
    {
        $ecole = Institution::factory()->create();
        $ecole->delete();
        $ecole->forceFill(['deleted_at' => now()->subDays($jours)])->saveQuietly();

        return $ecole;
    }

    /** Une ligne de l'école dans `$table`, sans rien dans les quatre anciennes relations. */
    private function peupler(string $table, Institution $ecole): void
    {
        if ($table === 'matieres') {
            Matiere::factory()->create(['institution_id' => $ecole->id]);

            return;
        }

        // Le consentement d'un compte d'une AUTRE école : aucun `users` de l'école purgée.
        $ailleurs = User::factory()->create(['institution_id' => Institution::factory()->create()->id]);
        DB::table('consents')->insert([
            'institution_id' => $ecole->id,
            'user_id' => $ailleurs->id,
            'actor_user_id' => $ailleurs->id,
            'purpose' => 'recording',
            'granted' => true,
            'granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
