<?php

declare(strict_types=1);

namespace App\Services\Suivi\Decrochage;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Services\Evaluation\Student\EvaluationsOuvertesAuxEleves;
use App\Services\Evaluation\Student\KlassciLearnerEvaluationAudience;
use Illuminate\Database\Eloquent\Builder;

/**
 * École KLASSCI : l'évaluation désigne la classe par son identité KLASSCI (`klassci_classe_id`), la
 * règle de {@see KlassciLearnerEvaluationAudience::visibleIn()}. Une classe sans identité KLASSCI
 * n'a aucune évaluation.
 */
final class KlassciEvaluationsDeLaClasse implements EvaluationsDeLaClasse
{
    public function __construct(private readonly KlassciLearnerEvaluationAudience $audience) {}

    /** @return Builder<Evaluation> */
    public function de(Classe $classe): Builder
    {
        $klassci = $classe->klassci_id;

        return is_numeric($klassci)
            ? $this->audience->visibleIn((int) $klassci, (int) $classe->institution_id)
            : EvaluationsOuvertesAuxEleves::de(null);
    }
}
