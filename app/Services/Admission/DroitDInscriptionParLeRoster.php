<?php

declare(strict_types=1);

namespace App\Services\Admission;

use App\Models\Institution;
use App\Services\Roster\RosterAuthorityFactory;

/**
 * Le droit d'inscription d'une école, lu par la seule fabrique des autorités : `forInstitution()`, puis
 * `allowsLocalEnrolment()`, comme l'import (`ImportConfirmController`) et l'inscription par code
 * (`ClasseOuverteParCode`). Relu à chaque appel : aucune école n'est capturée.
 */
final class DroitDInscriptionParLeRoster implements DroitDInscription
{
    public function __construct(private readonly RosterAuthorityFactory $autorites) {}

    public function ouvertPour(Institution $ecole): bool
    {
        return $this->autorites->forInstitution($ecole)->allowsLocalEnrolment();
    }
}
