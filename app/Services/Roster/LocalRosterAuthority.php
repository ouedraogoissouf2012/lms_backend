<?php

declare(strict_types=1);

namespace App\Services\Roster;

/**
 * Roster tenu par le LMS (#805).
 *
 * L'établissement est maître de sa liste : l'inscription locale — invitation,
 * code de session, import CSV — est la seule source.
 */
final class LocalRosterAuthority implements RosterAuthority
{
    public function allowsLocalEnrolment(): bool
    {
        return true;
    }
}
