<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\Seance;
use Psr\Log\LoggerInterface;

/**
 * Archive, tenant par tenant, les séances actives absentes d'un run de sync
 * KLASSCI complet.
 *
 * Extrait de `KlassciSeancesSyncService` pour garder le fichier sous la
 * limite §1.1 (≤300 lignes) : responsabilité unique (archivage), aucun
 * couplage avec la logique de récupération/upsert.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §1.6 D (DI strict)
 */
final class StaleSeanceArchiver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * N'archive que dans les institutions réellement synchronisées (#473).
     *
     * @param  array<int, array<int, int>>  $activeIdsByInstitution
     */
    public function archive(array $activeIdsByInstitution, SeanceSyncStats $stats): void
    {
        foreach ($activeIdsByInstitution as $institutionId => $activeIds) {
            $archivedSeances = Seance::withoutGlobalScope('institution')
                ->where('institution_id', $institutionId)
                ->where('is_active', true)
                ->whereNotNull('klassci_seance_id')
                ->whereNotIn('klassci_seance_id', $activeIds)
                ->get();

            foreach ($archivedSeances as $seance) {
                $seance->update([
                    'is_active' => false,
                    'archived_at' => now(),
                    'archive_reason' => 'supprimee_klassci',
                ]);
                $stats->seancesArchived++;

                $this->logger->info('Séance archivée (supprimée de Klassci)', [
                    'seance_id' => $seance->id,
                    'klassci_seance_id' => $seance->klassci_seance_id,
                    'institution_id' => $institutionId,
                    'matiere' => $seance->matiere_nom,
                ]);
            }
        }
    }
}
