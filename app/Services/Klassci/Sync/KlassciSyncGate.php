<?php

declare(strict_types=1);

namespace App\Services\Klassci\Sync;

use App\Models\User;

/**
 * #712 — faut-il relancer un auth/me KLASSCI pour cet utilisateur ?
 * Nommée par la préoccupation. Aucun HTTP ici.
 *
 * Le garde retient aussi l'échec d'une relance : c'est lui qui décide de la
 * suivante. Sans cette mémoire, un 401 ou un 429 (ni mis en cache, ni compté
 * par le disjoncteur) relançait auth/me à chaque requête du compte.
 */
interface KlassciSyncGate
{
    public function shouldRefresh(User $user): bool;

    /** La relance de ce compte a échoué (exception ou réponse illisible). */
    public function refreshFailed(User $user): void;
}
