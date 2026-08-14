<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;

/**
 * Résout localement le `klassci_matiere_id` d'une séance déjà synchronisée
 * (colonne indexée `seances.klassci_matiere_id`), pour éviter un scan HTTP
 * complet des matières quand la séance est déjà connue du cache local.
 *
 * ## Isolation tenant — AUCUN withoutGlobalScope
 *
 * Ce collaborateur s'exécute en contexte HTTP (tenant résolu) : le global
 * scope `institution` de {@see Seance} (trait `BelongsToInstitution`) reste
 * ACTIF. Même invariant que {@see LocalSeanceLookup}.
 *
 * Non-`final` : mocké directement par `KlassciSeanceMatiereScannerTest` pour
 * piloter précisément les scénarios fast-path/fallback/désynchronisation sans
 * dépendre d'un état DB — même dérogation que {@see KlassciProxyService}.
 *
 * @see KlassciSeanceMatiereScanner — seul consommateur (fast path, issue #517)
 */
class LocalSeanceMatiereResolver
{
    public function matiereIdFor(int $klassciSeanceId): ?int
    {
        return KlassciPayload::toInt(
            Seance::where('klassci_seance_id', $klassciSeanceId)->value('klassci_matiere_id')
        );
    }
}
