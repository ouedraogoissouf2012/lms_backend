<?php

declare(strict_types=1);

namespace App\Services\Bulletin;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Catalogue\PorteDuCatalogueLocal;

/**
 * #1677 : les bulletins sont ceux de l'école qui tient son catalogue. Dans une école KLASSCI, le
 * bulletin reste à KLASSCI : le LMS n'en fabrique pas de concurrent (403 motivé). Le droit est celui
 * de la porte du catalogue local, lu en UN lieu ({@see PorteDuCatalogueLocal::ouvertePour()}), jamais
 * une comparaison de mode.
 */
final class PorteDesBulletins
{
    public const REASON = 'report_cards_managed_by_klassci';

    public function __construct(private readonly PorteDuCatalogueLocal $catalogue) {}

    /** L'établissement de l'acteur, si ses bulletins sont tenus dans le LMS. */
    public function ecoleDe(User $acteur): int
    {
        if (! $this->catalogue->ouvertePour($acteur->institution)) {
            throw new BusinessException('Les bulletins de cet établissement sont tenus par KLASSCI.', 403, null, self::REASON);
        }

        return (int) $acteur->institution_id;
    }
}
