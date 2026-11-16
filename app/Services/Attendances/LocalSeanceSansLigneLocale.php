<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\User;

/** École autonome : ses séances sont toutes des lignes locales. Sans ligne, aucune séance. */
final class LocalSeanceSansLigneLocale implements SeanceSansLigneLocale
{
    public function decrire(int $seanceId, User $user): ?array
    {
        return null;
    }
}
