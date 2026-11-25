<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;

/**
 * Dans une école autonome, une séance se désigne par son id LOCAL seulement,
 * dans l'école du compte, comme l'activation et la bascule locales.
 *
 * Une école passée de KLASSCI au mode autonome garde des `klassci_seance_id` :
 * un id sans séance locale ne désigne jamais, par ce second identifiant, une
 * AUTRE séance (#933).
 */
final class LocalSeanceDesignee implements SeanceDesigneeStrictement
{
    public function pour(int $seanceId, User $user): ?Seance
    {
        if (! is_int($user->institution_id)) {
            return null;
        }

        return Seance::query()->whereKey($seanceId)->where('institution_id', $user->institution_id)->first();
    }
}
