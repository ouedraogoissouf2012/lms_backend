<?php

declare(strict_types=1);

namespace App\Services\Attendances\Suivi;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Attendances\Appel\KlassciSaisieDeLAppel;

/**
 * École créée depuis KLASSCI : elle tient ses propres présences, et le LMS n'en lit aucune marque (ARRÊT 1 de #1683,
 * Q1). Même motif que l'appel : c'est la même décision.
 */
final class KlassciSuiviDesPresences implements SuiviDesPresences
{
    public function lister(User $gestion, FiltresDePresences $filtres): array
    {
        throw self::refus();
    }

    public function synthese(User $gestion, FiltresDePresences $filtres, int $seuil): array
    {
        throw self::refus();
    }

    public function tenuDansLeLms(): bool
    {
        return true;
    }

    private static function refus(): BusinessException
    {
        return new BusinessException('Les présences de cette école se tiennent dans KLASSCI.', 409, null, KlassciSaisieDeLAppel::REASON);
    }
}
