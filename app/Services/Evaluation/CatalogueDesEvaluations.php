<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Le catalogue d'une évaluation, par type d'école (D72, E4b, C27 ; ADR-EI-01 « même
 * route, autre source ») : sa présentation, et l'espace des identifiants de ses
 * filtres.
 *
 * - École KLASSCI ({@see KlassciCatalogueDesEvaluations}) : le catalogue KLASSCI
 *   (classes et matières lues chez KLASSCI), et les identités KLASSCI.
 * - École créée depuis le LMS ({@see LocalCatalogueDesEvaluations}) : les libellés
 *   posés sur la ligne, et les ids locaux. Aucun appel à KLASSCI.
 *
 * Résolu À CHAQUE APPEL par `RosterAuthorityFactory::sourceForCurrentTenant` :
 * `EvaluationListService` est reçu par le constructeur d'un contrôleur que Laravel
 * garde sur l'objet Route.
 */
interface CatalogueDesEvaluations
{
    /**
     * @param  Collection<int, Evaluation>  $evaluations
     * @return array<int, array<string, mixed>>
     */
    public function presenter(Collection $evaluations, User $appelant): array;

    /** La colonne d'un filtre, prise dans une liste fermée. */
    public function colonne(FiltreDeListe $filtre): string;
}
