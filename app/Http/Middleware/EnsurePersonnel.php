<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;

/**
 * La garde `personnel` : les routes du personnel de l'établissement, ouvertes à qui
 * `User::isStaff()` admet (enseignant, coordinateur, admin, superAdmin). D499/D502.
 */
final class EnsurePersonnel extends EnsurePredicatDeRole
{
    public const ALIAS = 'personnel';

    protected function admet(User $user): bool
    {
        return $user->isStaff();
    }
}
