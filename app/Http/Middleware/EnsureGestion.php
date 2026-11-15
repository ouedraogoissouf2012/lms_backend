<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;

/**
 * La garde `gestion` : les routes de gestion de l'établissement, ouvertes à qui
 * `User::isManager()` admet (coordinateur, admin, superAdmin). D499/D502.
 */
final class EnsureGestion extends EnsurePredicatDeRole
{
    public const ALIAS = 'gestion';

    protected function admet(User $user): bool
    {
        return $user->isManager();
    }
}
