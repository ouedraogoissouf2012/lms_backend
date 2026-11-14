<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * La fin d'une {@see \App\Models\Evaluation} : UNE règle (C29), lue par le statut
 * effectif, le passage en entraînement et la fenêtre d'une école créée depuis le
 * LMS (`LocalEvaluationWindow`).
 *
 * - `deadline_at` s'il est posé, sinon `date_evaluation + duree_minutes` ;
 * - une fin antérieure à l'ouverture est ramenée à l'ouverture : jamais
 *   « terminée sans avoir commencé » ;
 * - sans date, aucune fin.
 *
 * Avant, `getEffectiveStatus` ignorait `deadline_at` quand la fenêtre locale le
 * lisait : deux fins dès que `deadline_at` était posée. Et la fin était exclue de
 * l'une (`>`), incluse dans l'autre (`>=`) ; elle est incluse partout, comme
 * `has_ended`.
 *
 * Dans une école KLASSCI, la fenêtre de la programmation reste lue chez KLASSCI :
 * elle borne le démarrage et l'échéance des tentatives, pas ce statut (divergence
 * déclarée, Q7 de l'ARRÊT 1 de C28).
 *
 * Extraire ce concern garde `Evaluation` sous la limite de 150 lignes (§5).
 *
 * @property string|null $status
 * @property Carbon|null $date_evaluation
 * @property Carbon|null $deadline_at
 * @property int|null $duree_minutes
 */
trait FinDeLEvaluation
{
    public function finPrevue(): ?CarbonInterface
    {
        $debut = $this->date_evaluation;
        if (! $debut instanceof CarbonInterface) {
            return null;
        }

        $fin = $this->deadline_at instanceof CarbonInterface
            ? $this->deadline_at->copy()
            : $debut->copy()->addMinutes((int) $this->duree_minutes);

        return $fin->lessThan($debut) ? $debut->copy() : $fin;
    }

    public function estTermineeA(CarbonInterface $maintenant): bool
    {
        $fin = $this->finPrevue();

        return $fin !== null && $maintenant->greaterThanOrEqualTo($fin);
    }

    /**
     * Le statut EFFECTIF : `terminee` dès la fin prévue atteinte, sinon le statut
     * stocké. Un brouillon non daté garde son statut (#268).
     */
    public function getEffectiveStatus(): string
    {
        if ($this->status === 'terminee' || $this->estTermineeA(Carbon::now())) {
            return 'terminee';
        }

        return (string) $this->status;
    }

    public function isTerminee(): bool
    {
        return $this->getEffectiveStatus() === 'terminee';
    }
}
