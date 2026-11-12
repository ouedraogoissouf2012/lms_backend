<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Support\Klassci\KlassciPersonalToken;

/**
 * Les matières de l'établissement, dans une école créée depuis KLASSCI : les
 * deux appels que faisaient les contrôleurs eux-mêmes, inchangés.
 *
 * Le jeton PERSONNEL est exigé ici, et non plus dans les contrôleurs : il y
 * aurait refusé l'école créée depuis le LMS avant tout choix de source. Un jeton
 * absent lève {@see MissingKlassciTokenException}, que les deux contrôleurs
 * rendent par la réponse qu'ils rendaient déjà (401 `klassci_token_missing`).
 * KLASSCI scope les matières selon le porteur (#616).
 */
final class KlassciInstitutionMatieresReader implements InstitutionMatieresReader
{
    public function __construct(
        private readonly KlassciProxyService $klassci,
        private readonly AdminMatieresQueryService $admin,
    ) {}

    /**
     * @param  array<string, mixed>  $filtres
     * @return array<string, mixed>
     */
    public function list(User $user, array $filtres): array
    {
        return $this->klassci->getMatieres($this->jeton($user), $filtres);
    }

    /**
     * @return array{matieres: array<int, array<string, mixed>>, statistiques: array<string, mixed>}
     */
    public function listForAdmin(User $user): array
    {
        return $this->admin->listForAdmin($this->jeton($user));
    }

    /**
     * La lecture de `ResolvesPersonalKlassciToken::personalKlassciToken`, que
     * les deux contrôleurs appliquaient : toute chaîne non vide est un jeton.
     */
    private function jeton(User $user): string
    {
        return KlassciPersonalToken::de($user) ?? throw MissingKlassciTokenException::forUser($user->id);
    }
}
