<?php

declare(strict_types=1);

namespace App\Services\Enseignant;

use App\Models\User;
use App\Services\Roster\RosterAuthorityFactory;

/**
 * L'id du compte DANS L'ESPACE DE L'ANNUAIRE des enseignants de son école (#1536) : la valeur
 * qu'il y reconnaîtra comme sienne, sans que le client apprenne le mode de l'école (#805).
 *
 * La source est choisie au seul `RosterAuthorityFactory`, par `sourceForAccount` et non par le
 * tenant : à la connexion, le tenant n'est pas forcément résolu (D496). C'est la même paire que
 * celle qui sert l'annuaire (`CatalogueServiceProvider`, `InstitutionTeachersReader`). Elle est
 * écrite deux fois ; `IdDansLAnnuaireTest` compare chaque valeur à l'annuaire RÉEL, dans les deux
 * volets, et rougirait si les deux paires divergeaient.
 */
final class IdDansLAnnuaire
{
    public function __construct(
        private readonly RosterAuthorityFactory $autorites,
    ) {}

    public function de(User $compte): ?int
    {
        return $this->autorites
            // F2 (falsification #1536) : la source locale forcée, quelle que soit l'école
            ->sourceForAccount($compte, LocalInstitutionTeachersReader::class, LocalInstitutionTeachersReader::class)
            ->idDansLAnnuaire($compte);
    }
}
