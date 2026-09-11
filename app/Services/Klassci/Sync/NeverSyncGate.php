<?php

declare(strict_types=1);

namespace App\Services\Klassci\Sync;

use App\Models\User;

/** Fake / mode local : jamais d'appel auth/me. */
final class NeverSyncGate implements KlassciSyncGate
{
    public function shouldRefresh(User $user): bool
    {
        return false;
    }
}
