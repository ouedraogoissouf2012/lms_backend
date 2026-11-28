<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Enums\ElementDeStructure;
use App\Http\Presenters\ElementDeStructureEnVue;

/**
 * La structure d'une école qui tient son catalogue : ses filières et ses niveaux d'études LOCAUX (#1648),
 * vides tant qu'elle n'en a créé aucun (lot 3c : des listes vides disent ce qui est, plutôt qu'un 503).
 *
 * La forme est celle du volet KLASSCI : `{id, nom, code}` (et `ordre` pour un niveau), la clé des niveaux
 * `niveaux_etudes` (`docs/INTEGRATION_KLASSCI.md`). Les trois lectures (`/proxy/structure`,
 * `/proxy/filieres`, `/proxy/niveaux-etudes`) passent ici : avant #1648, les deux dernières appelaient
 * KLASSCI même dans une école qui n'en a pas.
 */
final class LocalInstitutionStructureReader implements InstitutionStructureReader
{
    public function __construct(private readonly StructureDeLEcole $structure) {}

    public function structure(): array
    {
        return [
            'success' => true,
            'data' => [
                'filieres' => $this->liste(ElementDeStructure::Filiere),
                'niveaux_etudes' => $this->liste(ElementDeStructure::Niveau),
            ],
        ];
    }

    public function filieres(): array
    {
        return ['success' => true, 'data' => $this->liste(ElementDeStructure::Filiere)];
    }

    public function niveauxEtudes(): array
    {
        return ['success' => true, 'data' => $this->liste(ElementDeStructure::Niveau)];
    }

    /** @return list<array{id: int, nom: string, code: string|null, ordre?: int|null}> */
    private function liste(ElementDeStructure $element): array
    {
        return ElementDeStructureEnVue::liste($this->structure->lister($element));
    }
}
