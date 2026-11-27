<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * #1651 — UNE période « en cours » par école, et seulement une période publiée.
 *
 * La marque est portée par la PÉRIODE (`en_cours` : vrai, ou NULL) et non par l'école :
 * - elle ne peut désigner que sa propre école, par construction (aucune clé vers une autre) ;
 * - l'unique `(institution_id, en_cours)` n'en laisse qu'une par école, puisque NULL ne compte pas
 *   dans un unique.
 * Une clé composite depuis `institutions` aurait demandé `RESTRICT` : `SET NULL` y aurait mis à NULL
 * la clé primaire de l'école sous MySQL, et `RESTRICT` bloquerait la purge d'une période.
 *
 * L'invariant « seule une période PUBLIÉE est en cours » tient à tout instant, en base : un CHECK
 * sous MySQL (8.4 en production), deux triggers sous SQLite (patron de `2026_09_16_090000`). Il
 * refuse aussi la valeur « faux » : la marque est vraie ou absente, jamais l'un des deux sens d'un
 * booléen.
 */
return new class extends Migration
{
    private const CONTRAINTE = 'training_sessions_en_cours_publiee';

    private const UNIQUE = 'training_sessions_ecole_en_cours_unique';

    public function up(): void
    {
        if (! Schema::hasColumn('training_sessions', 'en_cours')) {
            Schema::table('training_sessions', static function (Blueprint $table): void {
                $table->boolean('en_cours')->nullable()->after('status');
                $table->unique(['institution_id', 'en_cours'], self::UNIQUE);
            });
        }

        if (DB::getDriverName() === 'sqlite') {
            foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $nom => $evenement) {
                DB::statement(sprintf(
                    'CREATE TRIGGER IF NOT EXISTS %s_%s BEFORE %s ON training_sessions FOR EACH ROW WHEN NOT %s '
                    ."BEGIN SELECT RAISE(ABORT, 'training_sessions : seule une periode publiee est en cours (#1651)'); END",
                    self::CONTRAINTE,
                    $nom,
                    $evenement,
                    $this->condition('NEW.'),
                ));
            }

            return;
        }

        if (! $this->contrainteMysqlPosee()) {
            DB::statement(sprintf('ALTER TABLE training_sessions ADD CONSTRAINT %s CHECK %s', self::CONTRAINTE, $this->condition('')));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(sprintf('DROP TRIGGER IF EXISTS %s_insert', self::CONTRAINTE));
            DB::statement(sprintf('DROP TRIGGER IF EXISTS %s_update', self::CONTRAINTE));
        } elseif ($this->contrainteMysqlPosee()) {
            DB::statement(sprintf('ALTER TABLE training_sessions DROP CHECK %s', self::CONTRAINTE));
        }

        if (Schema::hasColumn('training_sessions', 'en_cours')) {
            Schema::table('training_sessions', static function (Blueprint $table): void {
                $table->dropUnique(self::UNIQUE);
                $table->dropColumn('en_cours');
            });
        }
    }

    /** NULL, ou vrai sur une période publiée. */
    private function condition(string $prefixe): string
    {
        return sprintf("(%1\$sen_cours IS NULL OR (%1\$sen_cours = 1 AND %1\$sstatus = 'publiee'))", $prefixe);
    }

    private function contrainteMysqlPosee(): bool
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::connection()->getDatabaseName())
            ->where('TABLE_NAME', 'training_sessions')
            ->where('CONSTRAINT_NAME', self::CONTRAINTE)
            ->exists();
    }
};
