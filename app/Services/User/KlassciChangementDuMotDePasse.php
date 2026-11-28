<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Compte d'une école KLASSCI (#1667) : le mot de passe est celui de KLASSCI. La connexion essaie le local d'abord : un
 * mot de passe local ouvrirait une seconde porte, à côté de KLASSCI (#1275). Refus motivé, rien n'est écrit. Le motif
 * est celui de la définition par l'administration : une seule règle, un seul identifiant de contrat.
 */
final class KlassciChangementDuMotDePasse implements ChangementDuMotDePasse
{
    public function ouvert(User $compte): void
    {
        throw new BusinessException('Le mot de passe se change dans KLASSCI.', 403, null, KlassciDefinitionDuMotDePasse::REASON);
    }

    public function changer(User $compte, string $nouveau, ?int $jetonCourant): void
    {
        $this->ouvert($compte);
    }
}
