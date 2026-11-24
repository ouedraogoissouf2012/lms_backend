<?php

declare(strict_types=1);

use App\Services\Tenancy\GardeDesComptesSansEcole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * #801 (PR 3) — un compte d'école a toujours une école ; seul le supradmin de plateforme vit
 * sans (`role = 'supradmin'`, le rôle EXACT de `isPlatformSupradmin()`).
 *
 * `users.institution_id` ne peut pas devenir NOT NULL : le supradmin doit garder NULL
 * (#511). La règle est donc conditionnelle au rôle :
 * - MySQL (8.4 en production, CHECK appliqués depuis 8.0.16) : un CHECK nommé. La clé
 *   `users.institution_id` est en RESTRICT : aucune action référentielle ne contredit le CHECK.
 * - SQLite : deux triggers BEFORE INSERT et BEFORE UPDATE (patron de `2026_09_16_090000`).
 *
 * La règle applicative #1181 (`InteractsWithRoles`) la double, pour une exception nommée ;
 * celle-ci voit aussi ce que l'application ne voit pas (un `DB::table`, un geste en base).
 *
 * La garde annule AVANT tout DDL s'il existe un compte d'école sans école.
 */
return new class extends Migration
{
    private const CONTRAINTE = 'users_ecole_sauf_supradmin';

    /**
     * `COALESCE` : un rôle NULL rendrait la condition « inconnue », et un CHECK la laisse passer.
     *
     * Comparaison BINAIRE, comme `isPlatformSupradmin()` (`===`) : sous MySQL, la collation de
     * `users.role` (`utf8mb4_unicode_ci`) ignore la casse, et `role = 'supradmin'` acceptait
     * `Supradmin` et `SUPRADMIN` sans école (mesuré : rouge sur la seule jambe MySQL). SQLite
     * compare déjà en binaire.
     */
    private const CONDITION = "(%s = 'supradmin' OR %s.institution_id IS NOT NULL)";

    private const ROLE_SQLITE = "COALESCE(%s.role, '')";

    private const ROLE_MYSQL = "CAST(COALESCE(%s.role, '') AS BINARY)";

    public function up(): void
    {
        app(GardeDesComptesSansEcole::class)->exigerAucunCompteDEcoleSansEcole();

        return;

        if ($this->sousSqlite()) {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $nom => $evenement) {
                DB::statement(sprintf(
                    'CREATE TRIGGER IF NOT EXISTS %s_%s BEFORE %s ON users FOR EACH ROW WHEN NOT %s '
                    ."BEGIN SELECT RAISE(ABORT, 'users : un compte d''ecole a une ecole, seul le supradmin vit sans (#801)'); END",
                    self::CONTRAINTE,
                    $nom,
                    $evenement,
                    sprintf(self::CONDITION, sprintf(self::ROLE_SQLITE, 'NEW'), 'NEW'),
                ));
            }

            return;
        }

        if (! $this->contrainteMysqlPosee()) {
            DB::statement(sprintf(
                'ALTER TABLE users ADD CONSTRAINT %s CHECK %s',
                self::CONTRAINTE,
                sprintf(self::CONDITION, sprintf(self::ROLE_MYSQL, 'users'), 'users'),
            ));
        }
    }

    public function down(): void
    {
        if ($this->sousSqlite()) {
            DB::statement(sprintf('DROP TRIGGER IF EXISTS %s_insert', self::CONTRAINTE));
            DB::statement(sprintf('DROP TRIGGER IF EXISTS %s_update', self::CONTRAINTE));

            return;
        }

        if ($this->contrainteMysqlPosee()) {
            DB::statement(sprintf('ALTER TABLE users DROP CHECK %s', self::CONTRAINTE));
        }
    }

    private function sousSqlite(): bool
    {
        return DB::connection()->getDriverName() === 'sqlite';
    }

    /** Rejouable : MySQL refuse une contrainte de même nom. */
    private function contrainteMysqlPosee(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'users')
            ->where('CONSTRAINT_NAME', self::CONTRAINTE)
            ->exists();
    }
};
