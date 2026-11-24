<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * #801 (PR 2) — rattache à l'école de leur compte les notifications écrites sans école.
 *
 * Mesuré en production le 23/11 : 8 notifications `visio_starting` sans `institution_id`,
 * toutes adressées à des enseignants qui ONT une école. L'écrivain est réparé à la source par
 * #1515 ; ces lignes en sont les traces.
 *
 * Seule une notification dont le COMPTE a une école est remplie : aucune école n'est inventée.
 * Ce qui reste à NULL arrête la migration suivante, par sa garde.
 *
 * Données seulement, aucun DDL : rejouable, et sans effet quand il n'y a rien à remplir.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')
            ->whereNull('institution_id')
            ->whereExists(static fn (Builder $compte): Builder => $compte->from('users')
                ->whereColumn('users.id', 'notifications.user_id')
                ->whereNotNull('users.institution_id'))
            ->update([
                'institution_id' => DB::raw('(select users.institution_id from users where users.id = notifications.user_id)'),
            ]);
    }

    /** Rien à défaire : remettre NULL recréerait le défaut que cette migration répare. */
    public function down(): void {}
};
