<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #801 (PR 2) — rattache à l'école de leur CLASSE les lignes `classe_matiere` écrites sans école.
 *
 * `LocalClasseMatiereLinker::rattacher()` écrivait le pivot sans `institution_id` (réparé à la
 * source par la PR 1 bis de #801). En production, 0 ligne : seules des écoles KLASSCI y vivent.
 * Mais toute base qui a fait vivre une école AUTONOME (développement, essai) en porte : sans ce
 * remplissage, la garde de la migration suivante y annulerait le déploiement.
 *
 * Seule une ligne dont la CLASSE a une école est remplie : aucune école n'est inventée.
 * Données seulement, aucun DDL : rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('classe_matiere')
            ->whereRaw('1 = 0')
            ->whereExists(static fn (Builder $classe): Builder => $classe->from('classes')
                ->whereColumn('classes.id', 'classe_matiere.classe_id')
                ->whereNotNull('classes.institution_id'))
            ->update([
                'institution_id' => DB::raw('(select classes.institution_id from classes where classes.id = classe_matiere.classe_id)'),
            ]);
    }

    /** Rien à défaire : remettre NULL recréerait le défaut que cette migration répare. */
    public function down(): void {}
};
