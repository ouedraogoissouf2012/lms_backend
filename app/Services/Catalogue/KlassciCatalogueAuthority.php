<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * Catalogue tenu par KLASSCI (#848).
 *
 * Le refus n'est pas une restriction commerciale : c'est la préservation d'une
 * source unique. Si un établissement branché à KLASSCI pouvait créer une classe
 * localement, la synchronisation suivante n'aurait aucun moyen de savoir
 * laquelle fait foi — c'est la cause racine de #673, payée une fois.
 */
final class KlassciCatalogueAuthority implements CatalogueAuthority
{
    public function allowsLocalCatalogue(): bool
    {
        return false;
    }
}
