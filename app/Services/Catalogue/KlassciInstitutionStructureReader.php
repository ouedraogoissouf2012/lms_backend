<?php

declare(strict_types=1);

namespace App\Services\Catalogue;

use App\Services\KlassciProxyService;

/**
 * La structure de l'établissement, dans une école créée depuis KLASSCI : la
 * réponse de KLASSCI, relayée telle quelle.
 *
 * C'est l'appel que `ProxyOrganisationController::structure` faisait lui-même :
 * rien ne change dans ce qui est rendu. Un échec de KLASSCI remonte, et le
 * contrôleur le rend par `RendersKlassciProxyErrors`.
 */
final class KlassciInstitutionStructureReader implements InstitutionStructureReader
{
    public function __construct(
        private readonly KlassciProxyService $klassci,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function structure(): array
    {
        return $this->klassci->getStructure();
    }
}
