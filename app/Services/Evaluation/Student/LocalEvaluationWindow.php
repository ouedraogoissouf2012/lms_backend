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
 * - fermeture : `deadline_at` s'il est posé, sinon `date_evaluation +
 *   duree_minutes` ;
 * - sans date : fermée (fail-closed, comme #499) ;
 * - une fermeture antérieure à l'ouverture est ramenée à l'ouverture : la
 *   fenêtre est vide, jamais « terminée sans avoir commencé ».
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
        $fin = $evaluation->deadline_at instanceof CarbonInterface
            ? $evaluation->deadline_at
            : $debut->copy()->addMinutes((int) $evaluation->duree_minutes);
        $fin = $fin->lessThan($debut) ? $debut : $fin;
        $commencee = $maintenant->greaterThanOrEqualTo($debut);
        $terminee = $maintenant->greaterThanOrEqualTo($fin);

        return [
            'is_open' => $commencee && ! $terminee,
            'has_started' => $commencee,
            'has_ended' => $terminee,
            'start_at' => $debut->toIso8601String(),
            'end_at' => $fin->toIso8601String(),
        ];
    }
}
