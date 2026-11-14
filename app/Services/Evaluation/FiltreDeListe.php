<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

/**
 * Les filtres de `GET /evaluations` qui désignent un objet du catalogue (D72, E4b) :
 * une liste FERMÉE. La colonne de chacun dépend du type d'école
 * ({@see CatalogueDesEvaluations::colonne()}) ; aucune chaîne libre ne va jusqu'au SQL.
 */
enum FiltreDeListe: string
{
    case Classe = 'classe';
    case Matiere = 'matiere';
}
