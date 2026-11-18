<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Classe;
use App\Models\Evaluation;

/**
 * École créée depuis le LMS : la classe `classe_id` de l'évaluation, celle que
 * `LocalLearnerEvaluationAudience` ouvre à ses élèves. Bornée explicitement à l'école de
 * l'évaluation : le scope multi-tenant est inerte en console.
 */
final class LocalClasseDeLEvaluation implements ClasseDeLEvaluation
{
    public function de(Evaluation $evaluation): ?Classe
    {
        if ($evaluation->classe_id === null) {
            return null;
        }

        return Classe::query()
            ->withoutGlobalScope('institution')
            ->where('institution_id', $evaluation->institution_id)
            ->whereKey($evaluation->classe_id)
            ->first();
    }
}
