<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\Institution;
use App\Services\TenantManager;

/**
 * Le worker n'exécute pas ResolveInstitution (#536 / #718).
 */
trait RunsForInstitution
{
    abstract protected function institutionId(): int;

    protected function bindTenant(TenantManager $tenants): void
    {
        $institution = Institution::query()->find($this->institutionId());
        if ($institution instanceof Institution) {
            $tenants->set($institution);
        }
    }
}
