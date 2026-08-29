<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issue #473 — La table `seances` portait `klassci_seance_id->unique()` GLOBAL,
 * alors qu'en multi-tenant deux institutions ont des backends KLASSCI indépendants
 * et peuvent posséder le même `klassci_seance_id` (espaces d'ID par tenant). Le
 * sync de la 2e institution entrait en collision sur une seule ligne physique et
 * écrasait les données de la 1re (même défaut que #258 sur `matieres`, résolu par
 * `fix_matieres_unique_per_institution`, et #classes).
 *
 * Deux volets indissociables :
 *  1. Backfill des `institution_id` NULL existants (déduits via l'auteur de la
 *     ligne `created_by → users.institution_id`), sinon le composite unique reste
 *     inopérant (en SQL, plusieurs NULL sont autorisés dans un index unique).
 *  2. Remplacement de l'unique global par un unique composite
 *     `(klassci_seance_id, institution_id)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Backfill : rattacher les séances orphelines à l'institution de leur
        //    créateur. Sans cela, deux lignes (NULL, 42) resteraient permises et
        //    la contrainte composite ne protégerait pas les données historiques.
        DB::table('seances')
            ->whereNull('institution_id')
            ->whereNotNull('created_by')
            ->update([
                'institution_id' => DB::raw(
                    '(SELECT users.institution_id FROM users WHERE users.id = seances.created_by)'
                ),
            ]);

        // 2. Unique global → unique composite (une séance KLASSCI par institution).
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropUnique('seances_klassci_seance_id_unique');
            $table->unique(
                ['klassci_seance_id', 'institution_id'],
                'seances_klassci_institution_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('seances', function (Blueprint $table): void {
            $table->dropUnique('seances_klassci_institution_unique');
            $table->unique('klassci_seance_id', 'seances_klassci_seance_id_unique');
        });
    }
};
