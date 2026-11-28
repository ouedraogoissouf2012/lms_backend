<?php

declare(strict_types=1);

namespace App\Services\Attendances\Appel;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * École créée depuis KLASSCI : l'appel se saisit dans KLASSCI (`POST /proxy/cours/{id}/presences`). Le LMS n'en tient
 * pas une seconde vérité : deux sources écrivant la même présence est la cause racine de #673.
 */
final class KlassciSaisieDeLAppel implements SaisieDeLAppel
{
    public const REASON = 'attendance_managed_by_klassci';

    public function lire(int $seanceId, User $acteur): array
    {
        throw self::refus();
    }

    public function saisir(int $seanceId, User $acteur, array $marques): void
    {
        throw self::refus();
    }

    private static function refus(): BusinessException
    {
        return new BusinessException('L\'appel de cette école se saisit dans KLASSCI.', 409, null, self::REASON);
    }
}
