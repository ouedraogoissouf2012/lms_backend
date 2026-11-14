<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * La fenêtre d'une évaluation d'une école créée depuis le LMS, calculée sur sa
 * ligne (D72, Q2 décidée en D132) :
 * - ouverture : `date_evaluation` ;
 * - fermeture : la fin de l'évaluation, UNE règle partagée avec le statut
 *   effectif et l'entraînement ({@see \App\Models\Concerns\FinDeLEvaluation},
 *   C29) ;
 * - sans date : fermée (fail-closed, comme #499).
 *
 * Même forme que la fenêtre KLASSCI (`programmation.window`), que le front lit :
 * `{is_open, has_started, has_ended, start_at, end_at}`.
 */
final class LocalEvaluationWindow
{
    /**
     * @return array{is_open: bool, has_started: bool, has_ended: bool, start_at: string|null, end_at: string|null}
     */
    public static function of(Evaluation $evaluation, ?CarbonInterface $maintenant = null): array
    {
        $debut = $evaluation->date_evaluation;
        if (! $debut instanceof CarbonInterface) {
            return ['is_open' => false, 'has_started' => false, 'has_ended' => false, 'start_at' => null, 'end_at' => null];
        }

        $maintenant ??= Carbon::now();
        $fin = $evaluation->finPrevue() ?? $debut;
        $commencee = $maintenant->greaterThanOrEqualTo($debut);
        $terminee = $evaluation->estTermineeA($maintenant);

        return [
            'is_open' => $commencee && ! $terminee,
            'has_started' => $commencee,
            'has_ended' => $terminee,
            'start_at' => $debut->toIso8601String(),
            'end_at' => $fin->toIso8601String(),
        ];
    }
}
