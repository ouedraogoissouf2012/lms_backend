<?php

declare(strict_types=1);

namespace App\Services\Suivi\Decrochage;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Services\Evaluation\Student\EvaluationsOuvertesAuxEleves;
use Illuminate\Database\Eloquent\Builder;

/** École du LMS : l'évaluation désigne la classe par son id local (`classe_id`). */
final class LocalEvaluationsDeLaClasse implements EvaluationsDeLaClasse
{
    /** @return Builder<Evaluation> */
    public function de(Classe $classe): Builder
    {
        return EvaluationsOuvertesAuxEleves::de((int) $classe->institution_id)->where('classe_id', $classe->id);
    }
}
