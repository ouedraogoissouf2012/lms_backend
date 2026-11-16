<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Enums\Role;
use App\Models\User;
use App\Rules\AssignableRole;

/**
 * La règle de CIBLE de la gestion d'un compte (#932), écrite une fois et lue par la
 * suppression ET la modification d'un compte.
 *
 * L'acteur ne gère qu'un compte de rang STRICTEMENT inférieur au sien
 * ({@see Role::isMorePermissiveThan()}, la même source que
 * {@see AssignableRole}, qui borne le NOUVEAU rôle à la création et à la
 * modification), et jamais le sien. Échec fermé : un rôle inconnu, de l'acteur ou de
 * la cible, refuse.
 */
final class GestionDeCompte
{
    public static function admet(User $acteur, User $cible): bool
    {
        if ($acteur->is($cible)) {
            return false;
        }

        $rangActeur = $acteur->asRoleEnum();
        $rangCible = $cible->asRoleEnum();

        return $rangActeur !== null && $rangCible !== null && $rangActeur->isMorePermissiveThan($rangCible);
    }
}
