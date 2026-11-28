<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Classe;

use App\Exceptions\BusinessException;

/**
 * #1698 (C2) : une école KLASSCI tient ses notes dans KLASSCI. Une dispense du LMS, non poussée à KLASSCI, ferait
 * diverger les deux bilans en silence : elle est refusée, avant toute lecture du roster.
 */
final class KlassciPorteDesDispenses implements PorteDesDispenses
{
    public const REASON = 'grades_managed_by_klassci';

    public function ouvrir(): void
    {
        // FALSIFICATION : l'école KLASSCI ne refuse plus la dispense.
    }
}
