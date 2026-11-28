<?php

declare(strict_types=1);

namespace App\Services\Autorisation;

use App\Enums\Permission;
use App\Models\User;

/**
 * Ce qu'un compte a le droit de faire (#1665). Au lot 1, ses droits sont ceux de son rôle socle
 * ({@see PermissionsDuRole}). Les rôles d'école (lot 2) et les accès individuels (lot 3) s'ajouteront ICI, et
 * nulle part ailleurs : la garde de route et `/auth/me` liront cette seule source.
 */
final class DroitsDuCompte
{
    public function __construct(private readonly PermissionsDuRole $permissionsDuRole) {}

    public function accorde(User $compte, Permission $permission): bool
    {
        return in_array($permission, $this->de($compte), true);
    }

    /**
     * @return list<Permission>
     */
    public function de(User $compte): array
    {
        return $this->permissionsDuRole->de($compte->asRoleEnum());
    }
}
