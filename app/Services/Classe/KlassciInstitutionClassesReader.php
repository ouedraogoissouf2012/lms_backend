<?php

declare(strict_types=1);

namespace App\Services\Classe;

use App\Models\User;
use App\Services\KlassciProxyService;

/**
 * Les classes de l'établissement, dans une école créée depuis KLASSCI : la
 * réponse de KLASSCI, relayée telle quelle.
 *
 * C'est l'appel que `ProxyOrganisationController::classes` faisait lui-même :
 * rien ne change dans ce qui est rendu. Un échec de KLASSCI remonte, et le
 * contrôleur le rend par `RendersKlassciProxyErrors`.
 */
final class KlassciInstitutionClassesReader implements InstitutionClassesReader
{
    public function __construct(
        private readonly KlassciProxyService $klassci,
    ) {}

    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function list(User $user, array $filtres): array
    {
        return $this->klassci->getClasses($filtres);
    }
}
