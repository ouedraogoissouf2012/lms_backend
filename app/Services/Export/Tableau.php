<?php

declare(strict_types=1);

namespace App\Services\Export;

/**
 * Ce qu'un export rend, quel que soit son format (#1656) : un titre, des en-têtes, des lignes de texte, un nom de
 * fichier sans extension. Le CSV et le PDF sont tirés du MÊME tableau : ils ne peuvent pas diverger.
 */
final class Tableau
{
    /**
     * @param  list<string>  $entetes
     * @param  list<list<string>>  $lignes
     */
    public function __construct(
        public readonly string $titre,
        public readonly array $entetes,
        public readonly array $lignes,
        public readonly string $fichier,
    ) {}
}
