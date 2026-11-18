<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Classe;
use App\Models\Evaluation;

/**
 * École KLASSCI : la classe miroir de `klassci_classe_id`, celle que
 * `KlassciLearnerEvaluationAudience` ouvre à ses élèves. Le miroir est écrit par
 * `ClasseSyncService` ; il est lu ici, jamais demandé à KLASSCI. Borné explicitement à
 * l'école de l'évaluation (unique par `klassci_id` et école) : le scope multi-tenant est
 * inerte en console.
 */
final class KlassciClasseDeLEvaluation implements ClasseDeLEvaluation
{
    public function de(Evaluation $evaluation): ?Classe
    {
        if (! is_numeric($evaluation->klassci_classe_id)) {
            return null;
        }

        return Classe::query()
            ->withoutGlobalScope('institution')
            ->where('institution_id', $evaluation->institution_id)
            ->where('klassci_id', (int) $evaluation->klassci_classe_id)
            ->first();
    }
}
