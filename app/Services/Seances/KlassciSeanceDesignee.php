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
 * #1154, étape C : la SUPPRESSION ne passe plus par ici, mais par
 * {@see KlassciSeanceDesigneeStricte} (l'id KLASSCI seul). Restent la désactivation de la
 * visio et la modification, qui reçoivent encore deux espaces : leur dette, la même
 * ambiguïté sur une collision, est l'issue sœur de l'étape D.
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
