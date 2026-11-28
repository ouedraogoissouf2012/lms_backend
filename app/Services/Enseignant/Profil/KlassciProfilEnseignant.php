<?php

declare(strict_types=1);

namespace App\Services\Enseignant\Profil;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Une école créée depuis KLASSCI tient le profil de ses enseignants dans KLASSCI (#1666, ARRÊT 1, Q2) : le LMS ne le
 * lit ni ne l'écrit ici. La liste des enseignants relaie déjà ce que KLASSCI en publie (matricule, spécialité,
 * statut). Les disponibilités, elles, restent LOCALES dans les deux volets.
 */
final class KlassciProfilEnseignant implements ProfilEnseignantSource
{
    public const REASON = 'teacher_profile_managed_by_klassci';

    public function lire(User $enseignant): array
    {
        throw self::refus();
    }

    public function ecrire(User $enseignant, array $champs): array
    {
        throw self::refus();
    }

    private static function refus(): BusinessException
    {
        return new BusinessException('Le profil de cet enseignant se tient dans KLASSCI, pas ici.', 403, null, self::REASON);
    }
}
