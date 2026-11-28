<?php

declare(strict_types=1);

namespace App\Services\Suivi\Decrochage;

use App\Models\Classe;
use App\Models\Evaluation;
use Illuminate\Database\Eloquent\Builder;

/**
 * #1704 : les évaluations qu'un élève de cette classe voit, par l'identifiant que l'école range
 * sur l'évaluation : `classe_id` dans une école du LMS, `klassci_classe_id` dans une école
 * KLASSCI. Choisie par `RosterAuthorityFactory::sourceForInstitution`, sans appel KLASSCI.
 */
interface EvaluationsDeLaClasse
{
    /** @return Builder<Evaluation> */
    public function de(Classe $classe): Builder;
}
