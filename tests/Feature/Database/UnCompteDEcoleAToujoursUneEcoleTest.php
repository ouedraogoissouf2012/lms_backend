<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Exceptions\CompteSansEtablissementException;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * #801 (PR 3) — un compte d'école a toujours une école ; seul le supradmin de plateforme vit
 * sans.
 *
 * La règle #1181 (`InteractsWithRoles`) ne voyait que la CRÉATION Eloquent : ni une mise à
 * jour, ni un `DB::table`, ni un changement de rôle hors application. La base porte désormais
 * la règle : un CHECK sous MySQL (8.4 en production), deux triggers sous SQLite. Et la règle
 * applicative passe à la sauvegarde, pour qu'une mise à jour lève l'exception nommée.
 *
 * ⚠️ Le test de la garde rejoue `down()` puis `up()` : sous MySQL, un DDL auto-commite. Les
 * lignes sont effacées en `tearDown()`.
 */
final class UnCompteDEcoleAToujoursUneEcoleTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_11_24_090000_users_ecole_sauf_supradmin.php';

    protected function tearDown(): void
    {
        DB::table('users')->delete();
        DB::table('institutions')->delete();

        parent::tearDown();
    }

    public function test_la_base_refuse_un_compte_d_ecole_sans_ecole(): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->compte('enseignant') + ['institution_id' => null]);
    }

    public function test_la_base_refuse_de_retirer_son_ecole_a_un_compte(): void
    {
        $compte = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $compte->id)->update(['institution_id' => null]);
    }

    /** @return iterable<string, array{0: string}> */
    public static function variantesNonStrictes(): iterable
    {
        yield 'Supradmin' => ['Supradmin'];
        yield 'SUPRADMIN' => ['SUPRADMIN'];
        yield 'rôle vide' => [''];
    }

    /**
     * Seul le rôle EXACT `supradmin` vit sans école, comme `isPlatformSupradmin()` le lit. Ces
     * variantes étaient refusées par la porte (LessonWithoutInstitutionTest) ; c'est désormais la
     * base qui les refuse, avant toute porte.
     */
    #[DataProvider('variantesNonStrictes')]
    public function test_la_base_refuse_une_variante_non_stricte_sans_ecole(string $role): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert($this->compte($role) + ['institution_id' => null]);
    }

    public function test_la_base_accepte_le_supradmin_sans_ecole(): void
    {
        DB::table('users')->insert($this->compte('supradmin') + ['institution_id' => null]);

        self::assertSame(1, DB::table('users')->where('role', 'supradmin')->whereNull('institution_id')->count());
    }

    public function test_la_base_refuse_un_supradmin_retrograde_qui_reste_sans_ecole(): void
    {
        $id = DB::table('users')->insertGetId($this->compte('supradmin') + ['institution_id' => null]);

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $id)->update(['role' => 'enseignant']);
    }

    /** La règle #1181 voit aussi la MISE À JOUR : l'exception nommée, pas une erreur SQL. */
    public function test_retirer_son_ecole_a_un_compte_leve_l_exception_nommee(): void
    {
        $compte = User::factory()->create();

        $this->expectException(CompteSansEtablissementException::class);

        $compte->forceFill(['institution_id' => null])->save();
    }

    public function test_la_garde_annule_avant_tout_ddl_devant_un_compte_d_ecole_sans_ecole(): void
    {
        try {
            $this->migration()->down();
            DB::table('users')->insert($this->compte('etudiant') + ['institution_id' => null]);

            try {
                $this->migration()->up();
                self::fail('La garde devait annuler la migration.');
            } catch (RuntimeException $annulation) {
                self::assertStringContainsString('1 compte(s)', $annulation->getMessage());
                self::assertStringContainsString('institutions:audit-orphans', $annulation->getMessage());
            }
        } finally {
            DB::table('users')->whereNull('institution_id')->delete();
            $this->migration()->up();
        }
    }

    /** @return array<string, mixed> */
    private function compte(string $role): array
    {
        return [
            'name' => 'Compte '.$role,
            'email' => $role.'.'.bin2hex(random_bytes(4)).'@exemple.invalid',
            'password' => 'x',
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function migration(): Migration
    {
        $migration = require database_path('migrations/'.self::MIGRATION);
        self::assertInstanceOf(Migration::class, $migration);

        return $migration;
    }
}
