<?php

declare(strict_types=1);

namespace App\Services\Attendances\Emargement;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Attendances\Appel\KlassciSaisieDeLAppel;

/**
 * École créée depuis KLASSCI : l'émargement se fait dans KLASSCI (`/esbtp/attendance-codes`), comme l'appel (#1649). Le
 * LMS n'en tient pas une seconde vérité. Même motif que l'appel : c'est la même décision.
 */
final class KlassciEmargementParCode implements EmargementParCode
{
    public function generer(int $seanceId, User $auteur, int $dureeMinutes, ?int $retardApresMinutes): array
    {
        throw self::refus();
    }

    public function lister(int $seanceId, User $auteur): array
    {
        throw self::refus();
    }

    public function revoquer(int $codeId, User $auteur): void
    {
        throw self::refus();
    }

    public function signer(User $eleve, string $code): array
    {
        throw self::refus();
    }

    private static function refus(): BusinessException
    {
        return new BusinessException('L\'émargement de cette école se fait dans KLASSCI.', 409, null, KlassciSaisieDeLAppel::REASON);
    }
}
