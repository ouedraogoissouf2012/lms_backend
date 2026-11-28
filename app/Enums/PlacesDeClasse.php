<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Le filtre `places` de la collection des classes locales (#1652). `Pleine` suit la règle de
 * `Classe::isComplete()` : une capacité renseignée (> 0), atteinte par les adhésions ACTIVES.
 * `Disponibles` est son complément exact, une capacité non renseignée comprise (sans limite).
 */
enum PlacesDeClasse: string
{
    case Disponibles = 'disponibles';
    case Pleine = 'pleine';
}
