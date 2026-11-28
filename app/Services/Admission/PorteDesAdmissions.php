<?php

declare(strict_types=1);

namespace App\Services\Admission;

use App\Exceptions\BusinessException;
use App\Models\Institution;
use App\Models\User;

/**
 * La porte des demandes d'inscription (#1659) : une école les reçoit et les traite si elle inscrit elle-même ses
 * élèves ({@see DroitDInscription}).
 *
 * Même ordre que la porte du catalogue (#905) : l'établissement AVANT le droit. Sans établissement, 409
 * `no_institution` ; une école dont KLASSCI tient les inscriptions, 403 `enrolment_managed_by_klassci`. Le droit est
 * relu à chaque appel.
 */
final class PorteDesAdmissions
{
    public const REFUS = 'Les inscriptions de cet établissement viennent de KLASSCI.';

    public const REASON = 'enrolment_managed_by_klassci';

    public function __construct(private readonly DroitDInscription $droit) {}

    /** Le droit seul, sans refus : la porte publique, qui ne doit rien confirmer, s'en sert pour se taire. */
    public function ouvertePour(?Institution $ecole): bool
    {
        return $ecole instanceof Institution && $this->droit->ouvertPour($ecole);
    }

    /**
     * L'école de l'acteur, si elle reçoit les admissions.
     *
     * @throws BusinessException 409 `no_institution`, 403 `enrolment_managed_by_klassci`
     */
    public function etablissementDe(User $acteur): int
    {
        $ecole = $acteur->institution;
        if (! $ecole instanceof Institution) {
            throw new BusinessException('Aucun établissement résolu.', 409, reason: 'no_institution');
        }
        if (! $this->droit->ouvertPour($ecole)) {
            throw new BusinessException(self::REFUS, 403, reason: self::REASON);
        }

        return $ecole->id;
    }
}
