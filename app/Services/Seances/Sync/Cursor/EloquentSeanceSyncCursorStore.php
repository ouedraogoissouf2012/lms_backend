<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync\Cursor;

use App\Models\SeanceSyncCursor;
use Carbon\CarbonImmutable;

/**
 * Persistance du curseur de sync sur la table `seance_sync_cursors` (#582).
 *
 * La ligne est un singleton garanti par l'unique sur `name` : impossible de
 * dupliquer la position par une écriture concurrente.
 *
 * @see PRODUCTION_STANDARDS.md §5 (méthodes ≤40) · §1.6 D
 */
final class EloquentSeanceSyncCursorStore implements SeanceSyncCursorStore
{
    public function load(): SeanceSyncPosition
    {
        $row = SeanceSyncCursor::query()
            ->where('name', SeanceSyncCursor::KLASSCI_SEANCES)
            ->first();

        if ($row === null) {
            return SeanceSyncPosition::startOfCycle(CarbonImmutable::now());
        }

        return new SeanceSyncPosition(
            $row->last_institution_id,
            $row->last_user_id,
            CarbonImmutable::parse($row->cycle_started_at),
            $this->normalizeTaints($row->tainted_institution_ids),
        );
    }

    public function save(SeanceSyncPosition $position): void
    {
        SeanceSyncCursor::query()->updateOrCreate(
            ['name' => SeanceSyncCursor::KLASSCI_SEANCES],
            [
                'last_institution_id' => $position->lastInstitutionId,
                'last_user_id' => $position->lastUserId,
                'cycle_started_at' => $position->cycleStartedAt,
                'tainted_institution_ids' => array_values($position->taintedInstitutionIds),
            ],
        );
    }

    /**
     * Supprimer la ligne plutôt que la remettre à zéro : l'absence de ligne EST
     * l'état « début de cycle », et `load()` datera alors le nouveau cycle de
     * l'instant présent. Un seul état représente donc le début de cycle.
     */
    public function reset(): void
    {
        SeanceSyncCursor::query()
            ->where('name', SeanceSyncCursor::KLASSCI_SEANCES)
            ->delete();
    }

    /**
     * La colonne JSON peut porter n'importe quoi si elle est éditée à la main en
     * exploitation : on ne garde que des entiers, sans jamais faire échouer la
     * passe (une souillure illisible ne doit pas bloquer la sync).
     *
     * @param  mixed  $raw
     * @return array<int, int>
     */
    private function normalizeTaints($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                $ids[] = (int) $value;
            }
        }

        return array_values(array_unique($ids));
    }
}
