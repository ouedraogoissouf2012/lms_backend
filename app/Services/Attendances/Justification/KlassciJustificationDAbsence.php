<?php

declare(strict_types=1);

namespace App\Services\Attendances\Justification;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Services\Attendances\Appel\KlassciSaisieDeLAppel;
use Illuminate\Http\UploadedFile;

/**
 * École créée depuis KLASSCI : ses absences vivent dans KLASSCI, qui a son propre circuit de justificatifs ; le LMS
 * n'en tient pas une seconde vérité (ARRÊT 1 de #1681, Q1). Même motif que l'appel : c'est la même décision.
 */
final class KlassciJustificationDAbsence implements JustificationDAbsence
{
    public function mesAbsences(User $eleve): array
    {
        throw self::refus();
    }

    public function deposer(User $eleve, string $motif, ?array $seanceIds, ?string $du, ?string $au, ?UploadedFile $piece): array
    {
        throw self::refus();
    }

    public function mesJustificatifs(User $eleve): array
    {
        throw self::refus();
    }

    public function lister(User $gestion, FiltresDeJustificatifs $filtres): array
    {
        throw self::refus();
    }

    public function valider(int $justificatifId, User $gestion): array
    {
        throw self::refus();
    }

    public function rejeter(int $justificatifId, User $gestion, string $motif): array
    {
        throw self::refus();
    }

    public function piece(int $justificatifId, User $lecteur): PieceJustificative
    {
        throw self::refus();
    }

    private static function refus(): BusinessException
    {
        return new BusinessException('Les absences de cette école se justifient dans KLASSCI.', 409, null, KlassciSaisieDeLAppel::REASON);
    }
}
