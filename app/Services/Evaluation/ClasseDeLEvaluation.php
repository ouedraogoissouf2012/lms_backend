<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Classe;
use App\Models\Evaluation;

/**
 * La classe LOCALE d'une évaluation, par type d'école (#1247 ; ADR-EI-01 « même port,
 * autre source ») : celle dont les élèves VOIENT l'évaluation, dans le sens inverse de
 * `LearnerEvaluationAudience`.
 *
 * - École KLASSCI ({@see KlassciClasseDeLEvaluation}) : la classe miroir de
 *   `klassci_classe_id`.
 * - École créée depuis le LMS ({@see LocalClasseDeLEvaluation}) : la classe `classe_id`.
 *
 * Aucun appel à KLASSCI. Résolu par `RosterAuthorityFactory::sourceForInstitution`,
 * l'école de l'évaluation : le rappel du matin la lit en console, sans tenant.
 */
interface ClasseDeLEvaluation
{
    /** Null sans classe locale : l'évaluation n'a alors personne à qui être rappelée. */
    public function de(Evaluation $evaluation): ?Classe;
}
