<?php

declare(strict_types=1);

namespace App\Services\Attendances\Heures;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * École créée depuis KLASSCI : l'émargement des enseignants et leurs heures vivent dans KLASSCI
 * (`/esbtp/teacher-attendance`) ; le LMS n'en tient pas une seconde vérité.
 *
 * Même motif que l'appel (#1649, `KlassciSaisieDeLAppel::REASON`). DETTE TRACÉE : la constante de l'appel n'est pas
 * encore dans `lms` (PR #1754) ; la valeur est reprise ici, à unifier dès sa fusion.
 */
final class KlassciHeuresDeLEnseignant implements HeuresDeLEnseignant
{
    public const REASON = 'attendance_managed_by_klassci';

    public function mesHeures(User $enseignant, string $mois): array
    {
        throw self::refus();
    }

    public function rapport(User $gestion, FiltresDHeures $filtres): array
    {
        throw self::refus();
    }

    private static function refus(): BusinessException
    {
        return new BusinessException('Les heures des enseignants de cette école se tiennent dans KLASSCI.', 409, null, self::REASON);
    }
}
