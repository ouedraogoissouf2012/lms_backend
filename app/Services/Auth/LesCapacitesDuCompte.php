<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\Institution;
use App\Services\Attendances\Suivi\KlassciSuiviDesPresences;
use App\Services\Attendances\Suivi\LocalSuiviDesPresences;
use App\Services\Catalogue\PorteDuCatalogueLocal;
use App\Services\Roster\RosterAuthorityFactory;

/**
 * Le calcul UNIQUE des capacités que la connexion dit au front ({@see CapacitesDuCompte}), pour l'école du
 * compte. Chacune est demandée à l'autorité ou à la source de son domaine, choisie par
 * {@see RosterAuthorityFactory} : aucune ne lit le mode ici.
 *
 * Sans établissement (supradmin), aucune capacité : l'autorité la plus restrictive.
 */
final readonly class LesCapacitesDuCompte
{
    public function __construct(
        private RosterAuthorityFactory $autorites,
        private PorteDuCatalogueLocal $catalogue,
    ) {}

    public function de(?Institution $ecole): CapacitesDuCompte
    {
        if (! $ecole instanceof Institution) {
            return CapacitesDuCompte::aucune();
        }

        return new CapacitesDuCompte(
            peutInscrireLocalement: $this->autorites->forInstitution($ecole)->allowsLocalEnrolment(),
            peutGererCatalogueLocal: $this->catalogue->ouvertePour($ecole),
            peutSuivreLesPresences: $this->autorites
                ->sourceForInstitution($ecole, KlassciSuiviDesPresences::class, LocalSuiviDesPresences::class)
                ->tenuDansLeLms(),
        );
    }
}
