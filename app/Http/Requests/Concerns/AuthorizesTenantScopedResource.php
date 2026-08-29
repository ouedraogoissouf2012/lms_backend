<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * #622 — prélude tenant partagé par File / Forum / Chapter download.
 *
 * @return bool|null `true` autorisé d'office (supradmin), `false` refusé,
 *                   `null` poursuivre la règle métier.
 */
trait AuthorizesTenantScopedResource
{
    protected function passesTenantGuard(User $user, mixed $institutionId): ?bool
    {
        // Intentionnel : `'supradmin'` minuscules strictes.
        // Role::tryFromString normaliserait aussi `'superAdmin'` (admin
        // INTRA-tenant) et ouvrirait un accès cross-tenant (#102).
        // NE PAS migrer vers asRoleEnum() (#132).
        if ($user->isPlatformSupradmin()) {
            return true;
        }

        if ($institutionId === null || $institutionId !== $user->institution_id) {
            return false;
        }

        return null;
    }
}
