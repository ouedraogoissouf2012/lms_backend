<?php

declare(strict_types=1);

namespace App\Services\User;

use App\Exceptions\BusinessException;
use App\Models\User;

/**
 * Compte d'une école KLASSCI (#1667) : le profil vient de KLASSCI, et la synchronisation écraserait une saisie
 * locale. Refus motivé, rien n'est écrit.
 */
final class KlassciModificationDuProfil implements ModificationDuProfil
{
    public const REASON = 'profile_managed_by_klassci';

    public function ouvert(User $compte): void
    {
        throw new BusinessException('Le profil se modifie dans KLASSCI.', 403, null, self::REASON);
    }

    public function modifier(User $compte, array $champs): User
    {
        $this->ouvert($compte);

        return $compte;
    }
}
