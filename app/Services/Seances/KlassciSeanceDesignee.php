<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;

/**
 * Dans une école KLASSCI, la route de gestion reçoit DEUX espaces : le
 * calendrier de gestion envoie l'id KLASSCI (celui du détail), l'historique
 * l'id local (`SeancesHistoryQueryService`). L'espace y est donc réellement
 * inconnu : {@see Seance::localIdFor()} tranche, le local d'abord (ADR-805-01),
 * dans l'école du compte.
 *
 * DETTE #1154 : sur une collision des deux numérotations, l'id KLASSCI du
 * calendrier désigne la séance locale qui porte le même nombre. Le correctif
 * est l'ADR-760-01 (une porte par espace, l'historique sur la porte locale).
 */
final class KlassciSeanceDesignee implements SeanceDesignee
{
    public function pour(int $seanceId, User $user): ?Seance
    {
        if (! is_int($user->institution_id)) {
            return null;
        }

        $locale = Seance::localIdFor($seanceId, $user->institution_id);

        return $locale === null ? null : Seance::query()->find($locale);
    }
}
