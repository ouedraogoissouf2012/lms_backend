<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

/**
 * Catalogue tenu par le LMS (#848).
 *
 * L'établissement est maître de ses classes et de ses matières : il n'existe
 * aucune autre source, donc aucune réalité concurrente à réconcilier.
 */
final class LocalCatalogueAuthority implements CatalogueAuthority
{
    public function allowsLocalCatalogue(): bool
    {
        return true;
    }
}
