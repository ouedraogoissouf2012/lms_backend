<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Enums\EvaluationStatus;
use App\Models\Evaluation;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ce qu'un élève peut voir d'une évaluation, quelle que soit la source de sa
 * classe : publiée, d'un statut visible (le brouillon exclu), avec au moins
 * une question. Écrit une seule fois, pour les deux sources de l'audience.
 *
 * L'établissement est filtré EXPLICITEMENT : sans s'en remettre au scope
 * global, ouvert par défaut quand aucun tenant n'est posé. Sans établissement :
 * rien (`1 = 0`, sans `IS NULL` qui remonterait les lignes orphelines).
 */
final class EvaluationsOuvertesAuxEleves
{
    /**
     * @return Builder<Evaluation>
     */
    public static function de(?int $institutionId): Builder
    {
        $query = Evaluation::query();

        if ($institutionId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('institution_id', $institutionId)
            ->where('is_published', true)
            ->whereIn('status', [
                EvaluationStatus::Planifiee->value,
                EvaluationStatus::EnCours->value,
                EvaluationStatus::Terminee->value,
            ])
            ->whereHas('questions');
    }
}
