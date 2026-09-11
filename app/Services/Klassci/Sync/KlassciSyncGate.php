<?php

declare(strict_types=1);

namespace App\Services\Klassci\Sync;

use App\Models\User;

/**
 * #712 — faut-il relancer un auth/me KLASSCI pour cet utilisateur ?
 * Nommée par la préoccupation. Aucun HTTP ici.
 */
interface KlassciSyncGate
{
    public function shouldRefresh(User $user): bool;
}
