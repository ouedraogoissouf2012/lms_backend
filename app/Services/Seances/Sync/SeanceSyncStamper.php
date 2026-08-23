<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\Seance;
use Carbon\CarbonInterface;

/**
 * Marque les séances confirmées par KLASSCI durant le cycle courant (#582).
 *
 * ## Pourquoi un marquage plutôt qu'une liste en mémoire
 *
 * L'ancien archivage accumulait les identifiants actifs en mémoire, ce qui
 * n'avait de sens que si une passe couvrait toute la population. Avec la reprise
 * par curseur, un cycle s'étale sur plusieurs passes et l'accumulation est
 * perdue entre elles. Le marquage, lui, est porté par la ligne : il survit aux
 * coupures et reste en O(1) mémoire.
 *
 * ## Pourquoi `toBase()`
 *
 * `Model::update()` repousserait `updated_at` sur chaque séance à chaque passe,
 * détruisant sa sémantique de « dernière modification de contenu » — que
 * {@see \App\Services\Seances\SeanceCacheDataBuilder::applyTo()} protège
 * justement par son `isDirty()`. `toBase()` applique les scopes du modèle puis
 * rend le constructeur de requête sous-jacent, qui n'ajoute pas d'horodatage.
 *
 * @see PRODUCTION_STANDARDS.md §1.4 (une requête par enseignant, pas par séance)
 */
final class SeanceSyncStamper
{
    /**
     * Borne du `WHERE ... IN` : au-delà, MySQL paie un plan de requête inutile
     * (et `max_allowed_packet` finit par être atteint sur un enseignant très
     * chargé). Découpage plutôt que requête unique non bornée.
     */
    private const ID_CHUNK = 500;

    /**
     * Un UNIQUE `UPDATE` par lot d'identifiants — jamais une écriture par séance.
     *
     * @param  array<int, int>  $klassciSeanceIds  Séances confirmées pour ce tenant
     */
    public function stamp(int $institutionId, array $klassciSeanceIds, CarbonInterface $confirmedAt): void
    {
        $ids = array_values(array_unique($klassciSeanceIds));
        if ($ids === []) {
            return;
        }

        foreach (array_chunk($ids, self::ID_CHUNK) as $chunk) {
            // withoutGlobalScope : job cross-tenant — le scope est inerte hors
            // requête HTTP, on scope donc explicitement par institution (#473).
            Seance::withoutGlobalScope('institution')
                ->where('institution_id', $institutionId)
                ->whereIn('klassci_seance_id', $chunk)
                ->toBase()
                ->update(['synced_at' => $confirmedAt]);
        }
    }
}
