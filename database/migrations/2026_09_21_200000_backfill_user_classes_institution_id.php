<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Issue #878 — rattraper les lignes de `user_classes` écrites sans établissement.
 *
 * `StudentClassSynchronizer` tourne PENDANT le login, où aucun tenant n'est
 * résolu : `BelongsToInstitution` y passe en `log-and-no-op` et laissait la
 * colonne nulle. L'écrivain est corrigé, mais les lignes déjà écrites, non.
 *
 * ## Pourquoi rattraper plutôt que tolérer
 *
 * `UserClass` porte le scope multi-tenant, qui filtre précisément
 * `institution_id`. Une ligne nulle est donc invisible à **tous** ses lecteurs —
 * cinq, mesurés le 21/09 — y compris ceux qui ne filtrent rien eux-mêmes.
 *
 * Tolérer le nul en lecture s'écrirait dans ces cinq endroits et y resterait à
 * jamais. Ce rattrapage s'écrit une fois.
 *
 * ## La valeur n'est pas devinée, elle est déduite
 *
 * `user_classes.user_id` désigne un utilisateur qui porte, lui, son
 * établissement. Aucune heuristique : on recopie une donnée existante.
 *
 * Les lignes dont l'utilisateur n'a PAS d'établissement restent nulles et sont
 * COMPTÉES dans le journal. Elles signalent un compte hors établissement — un
 * supradmin, ou une reprise incomplète — pas une valeur à inventer.
 *
 * ## Idempotente
 *
 * Ne touche que les lignes nulles. Rejouer la migration ne change rien.
 *
 * @see docs/adr/2026-09-21-878-01-institution-du-miroir-de-classe.md
 */
return new class extends Migration
{
    public function up(): void
    {
        $orphelines = DB::table('user_classes')->whereNull('institution_id')->count();

        if ($orphelines === 0) {
            return;
        }

        // Sous-requête plutôt qu'un `join` en UPDATE : SQLite ne connaît pas
        // `UPDATE ... JOIN`, et la migration doit tourner sur les deux moteurs.
        DB::table('user_classes')
            ->whereNull('institution_id')
            ->update([
                'institution_id' => DB::raw(
                    '(select institution_id from users where users.id = user_classes.user_id)'
                ),
            ]);

        $restantes = DB::table('user_classes')->whereNull('institution_id')->count();

        Log::info('#878 — rattrapage de user_classes.institution_id', [
            'lignes_orphelines' => $orphelines,
            'lignes_rattrapees' => $orphelines - $restantes,
            'lignes_restantes' => $restantes,
            'note' => $restantes > 0
                ? 'Ces lignes appartiennent à des comptes sans établissement : valeur non devinable.'
                : 'Toutes rattachées.',
        ]);
    }

    /**
     * Irréversible, et c'est assumé : on ne sait pas distinguer une ligne
     * rattrapée d'une ligne qui portait déjà son établissement. Remettre le nul
     * partout recréerait le défaut sur des lignes saines.
     */
    public function down(): void
    {
        Log::info('#878 — rattrapage non annulé : les lignes saines ne sont pas distinguables des rattrapées.');
    }
};
