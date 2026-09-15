<?php

declare(strict_types=1);

namespace App\Services\Roster;

/**
 * Roster tenu par KLASSCI (#805).
 *
 * Les apprenants viennent de la synchronisation : une écriture locale créerait
 * une seconde liste à côté de celle qui fait foi, que rien ne réconcilierait.
 *
 * C'est aussi l'autorité rendue hors contexte d'établissement — un défaut
 * fail-secure : sans tenant résolu, on n'autorise rien.
 */
final class KlassciRosterAuthority implements RosterAuthority
{
    public function allowsLocalEnrolment(): bool
    {
        return false;
    }
}
