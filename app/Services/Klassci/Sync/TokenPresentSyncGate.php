<?php

declare(strict_types=1);

namespace App\Services\Klassci\Sync;

use App\Models\User;

/**
 * Re-sync seulement si le compte a un jeton KLASSCI et des données périmées.
 */
final class TokenPresentSyncGate implements KlassciSyncGate
{
    public function shouldRefresh(User $user): bool
    {
        if ($user->isKlassciDataFresh()) {
            return false;
        }

        $token = $user->klassci_token;

        return is_string($token) && $token !== '';
    }
}
