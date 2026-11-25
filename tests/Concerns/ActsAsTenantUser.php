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

    /**
     * #691 — la requête suivante part SANS identité : en-têtes ET garde purgés. Purger
     * les seuls en-têtes laisse le garde servir l'utilisateur déjà mémorisé.
     */
    protected function anonyme(): static
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this;
    }
}
