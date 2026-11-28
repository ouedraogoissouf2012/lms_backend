<?php

declare(strict_types=1);

namespace App\Support\Evaluation;

use App\Models\Evaluation;
use Illuminate\Database\Eloquent\Builder;

/**
 * #1702 (D3) : le nombre de questions POSÉES à chaque élève, celui que ses listes annoncent. Les
 * questions hors des viviers de tirage, plus, pour chaque règle, le nombre tiré ; jamais le
 * vivier entier. Sans tirage, le nombre de questions de l'évaluation, comme avant.
 */
final class NombreDeQuestionsPosees
{
    /**
     * Charge les deux comptes, en une requête de plus par liste (jamais une par évaluation).
     *
     * @param  Builder<Evaluation>  $evaluations
     * @return Builder<Evaluation>
     */
    public static function charger(Builder $evaluations): Builder
    {
        return $evaluations
            ->withCount(['questions as questions_hors_tirage_count' => static fn (Builder $q) => $q->whereNull('tirage_id')])
            ->withSum('tirages as questions_tirees_sum', 'nombre');
    }

    public static function de(Evaluation $evaluation): int
    {
        $horsTirage = $evaluation->getAttribute('questions_hors_tirage_count');
        $tirees = $evaluation->getAttribute('questions_tirees_sum');

        return (is_numeric($horsTirage) ? (int) $horsTirage : 0) + (is_numeric($tirees) ? (int) $tirees : 0);
    }
}
