<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Rattacher à leur établissement les copies écrites sans lui.
 *
 * La propriété d'une copie porte désormais l'établissement
 * (`EvaluationSubmission::scopeOwnedBy`) : l'identifiant KLASSCI n'est unique
 * qu'au sein d'une école. Une copie dont `institution_id` est nul n'appartient
 * donc plus à personne. Son auteur ne la voit plus, ni dans ses notes, ni dans
 * sa copie. Le décompte de ses tentatives l'ignore. Une tentative ouverte
 * ainsi orpheline fait échouer `/start` sur l'index unique `eval_sub_unique`.
 * Aucune migration n'avait rempli cette colonne pour les copies antérieures à
 * son ajout.
 *
 * ## La valeur n'est pas devinée, elle est déduite
 *
 * Une copie appartient, par définition, à l'école de son évaluation :
 * `/start` écrit `institution_id` depuis l'évaluation. On recopie donc
 * `evaluations.institution_id` (évaluations supprimées comprises : la copie
 * reste celle de cette école).
 *
 * Une copie dont l'évaluation n'a PAS d'établissement reste nulle, et elle est
 * COMPTÉE dans le journal : aucune valeur n'est à inventer.
 *
 * ## Idempotente, par lots
 *
 * Seules les copies nulles sont touchées, par lots de 500 identifiants : la
 * rejouer ne change rien.
 */
return new class extends Migration
{
    private const LOT = 500;

    public function up(): void
    {
        $orphelines = DB::table('evaluation_submissions')->whereNull('institution_id')->count();

        if ($orphelines === 0) {
            return;
        }

        DB::table('evaluation_submissions')
            ->whereNull('institution_id')
            ->orderBy('id')
            ->select('id')
            ->chunkById(self::LOT, function ($copies): void {
                // Sous-requête plutôt qu'un `join` en UPDATE : SQLite ne connaît
                // pas `UPDATE ... JOIN`, et la migration tourne sur les deux moteurs.
                DB::table('evaluation_submissions')
                    ->whereIn('id', $copies->pluck('id'))
                    ->whereNull('institution_id')
                    ->update([
                        'institution_id' => DB::raw(
                            '(select institution_id from evaluations where evaluations.id = evaluation_submissions.evaluation_id)'
                        ),
                    ]);
            });

        $restantes = DB::table('evaluation_submissions')->whereNull('institution_id')->count();

        Log::info('Rattrapage de evaluation_submissions.institution_id', [
            'copies_orphelines' => $orphelines,
            'copies_rattachees' => $orphelines - $restantes,
            'copies_restantes' => $restantes,
            'note' => $restantes > 0
                ? 'Ces copies portent une évaluation sans établissement : valeur non déductible.'
                : 'Toutes rattachées.',
        ]);
    }

    /**
     * Rien à défaire : une copie rattrapée ne se distingue pas de celles que
     * `/start` écrit avec leur établissement. Les remettre à nul retirerait leur
     * propriétaire à des copies saines.
     */
    public function down(): void {}
};
