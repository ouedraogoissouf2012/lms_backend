<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;

/**
 * Dans une école KLASSCI, la séance de l'id KLASSCI reçu, dans l'école du compte, et elle seule
 * (#1154, étape C). Aucun repli sur l'espace local : sur une collision des deux numérotations,
 * l'id KLASSCI du calendrier ne désigne plus la séance locale qui porte le même nombre.
 */
final class KlassciSeanceDesigneeStricte implements SeanceDesigneeStrictement
{
    public function pour(int $seanceId, User $user): ?Seance
    {
        if (! is_int($user->institution_id)) {
            return null;
        }

        return app(LocalSeanceDesignee::class)->pour($seanceId, $user);
    }
}
