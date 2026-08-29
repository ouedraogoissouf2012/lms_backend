<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Résout en mémoire l'état local (Seance locale, archivage, masquage, visio) d'un
 * ensemble de séances KLASSCI, à partir d'un pré-chargement en 2 requêtes SQL au
 * plus — au lieu d'un lookup unitaire par séance.
 *
 * ## Issue #476 — élimination des N+1 de UpcomingSeancesFetcher
 *
 * Remplace trois N+1 (`filterSeances` lookup + `isHidden`, `enrichWithVisio`
 * lookup) par deux `whereIn` groupés, résolus ensuite en mémoire. Un unique
 * pré-chargement `byKlassciId` alimente à la fois le filtrage et l'enrichissement
 * visio (mutualisation).
 *
 * ## Isolation tenant — AUCUN withoutGlobalScope (CRITIQUE)
 *
 * Ce collaborateur s'exécute en contexte HTTP (tenant résolu) : le global scope
 * `institution` de {@see Seance} et {@see SeanceUserHidden} (trait
 * BelongsToInstitution) est ACTIF et filtre par tenant courant. Les `whereIn`
 * DOIVENT rester des accès Eloquent standard, SANS `withoutGlobalScope` — sinon
 * fuite cross-tenant. C'est l'inverse du job KlassciSeancesSyncService (tenant-less).
 *
 * @see PRODUCTION_STANDARDS.md §1.4 (zéro N+1) · §1.6 D (DI, aucune Facade)
 */
final class LocalSeanceLookup
{
    /** @var Collection<int, Seance> Séances locales indexées par klassci_seance_id. */
    private Collection $byKlassciId;

    /** @var array<int, true> Ids LOCAUX (Seance->id) masqués pour l'utilisateur. */
    private array $hiddenLocalIds = [];

    public function __construct()
    {
        $this->byKlassciId = collect();
    }

    /**
     * Pré-charge en 2 requêtes au plus (1 si $student null) les Seance locales
     * correspondant aux klassci_seance_id fournis, plus le set masqué de l'étudiant.
     * Accès Eloquent standard : le scope global `institution` reste actif (HTTP).
     *
     * @param  list<int>  $klassciSeanceIds
     */
    public function preload(array $klassciSeanceIds, ?User $student): void
    {
        $ids = array_values(array_unique(array_filter($klassciSeanceIds, static fn (int $id): bool => $id > 0)));

        // withConnectedParticipantsCount() pré-charge `connected_participants_count`
        // en une passe (withCount) : sans lui, l'accessor appendé
        // `current_participants_count` (Seance::$appends) ferait une requête par
        // séance à la sérialisation — un N+1 résiduel qui annulerait le gain.
        $this->byKlassciId = $ids === []
            ? collect()
            : Seance::whereIn('klassci_seance_id', $ids)
                ->withConnectedParticipantsCount()
                ->get()
                ->keyBy('klassci_seance_id');

        $this->hiddenLocalIds = [];
        if ($student === null || $this->byKlassciId->isEmpty()) {
            return;
        }

        /** @var list<int> $localIds */
        $localIds = $this->byKlassciId->pluck('id')->all();

        $hidden = SeanceUserHidden::whereIn('seance_id', $localIds)
            ->where('user_id', $student->id)
            ->pluck('seance_id')
            ->filter(static fn (mixed $id): bool => is_int($id))
            ->all();

        /** @var list<int> $hidden */
        $this->hiddenLocalIds = array_fill_keys($hidden, true);
    }

    /**
     * Séance locale pour un klassci_seance_id, ou null si absente
     * (identique au ->first() nul des lookups remplacés).
     */
    public function seanceFor(?int $klassciSeanceId): ?Seance
    {
        if ($klassciSeanceId === null) {
            return null;
        }

        return $this->byKlassciId->get($klassciSeanceId);
    }

    /** True ssi une Seance locale existe ET est archivée (is_active === false). */
    public function isArchived(?int $klassciSeanceId): bool
    {
        $seance = $this->seanceFor($klassciSeanceId);

        return $seance !== null && $seance->is_active === false;
    }

    /** True ssi une Seance locale existe ET son id local est dans le set masqué. */
    public function isHidden(?int $klassciSeanceId): bool
    {
        $seance = $this->seanceFor($klassciSeanceId);

        return $seance !== null && isset($this->hiddenLocalIds[$seance->id]);
    }
}
