<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\User;

/**
 * #709 — jeton porteur réel. Sanctum::actingAs n'émet pas de Bearer :
 * ResolveInstitution ne pose pas le tenant, BelongsToInstitution skip.
 */
trait ActsAsTenantUser
{
    protected function tokenFor(User $user): string
    {
        return $user->createToken('test')->plainTextToken;
    }

    protected function asTenant(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->tokenFor($user));
    }
}
