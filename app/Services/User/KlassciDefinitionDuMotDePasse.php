<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * École KLASSCI (#1275) : le mot de passe est celui de KLASSCI. La connexion essaie le local
 * d'abord : un mot de passe local ouvrirait une seconde porte, à côté de KLASSCI. Refus
 * motivé, rien n'est écrit.
 */
final class KlassciDefinitionDuMotDePasse implements DefinitionDuMotDePasse
{
    public const REASON = 'password_managed_by_klassci';

    public function definir(User $cible, string $motDePasse): void
    {
        $cible->forceFill(['password' => $motDePasse])->save();
    }
}
