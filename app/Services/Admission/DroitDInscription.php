<?php

declare(strict_types=1);

namespace App\Services\Admission;

use App\Models\Institution;

/**
 * Le droit d'une école à inscrire elle-même ses élèves, tel que les admissions le lisent (#1659).
 *
 * Une admission EST une inscription : elle suit ce droit-là, et non celui du catalogue. Les deux valent pareil
 * aujourd'hui (une école autonome tient l'un et l'autre). Ce contrat existe pour qu'ils ne se confondent pas le jour où
 * ils divergeront, et pour qu'un test les fasse diverger : la fabrique des autorités est finale.
 *
 * Implémentation : {@see DroitDInscriptionParLeRoster}, qui lit `RosterAuthority::allowsLocalEnrolment()`, la même
 * règle que l'import et l'inscription par code.
 */
interface DroitDInscription
{
    public function ouvertPour(Institution $ecole): bool;
}
