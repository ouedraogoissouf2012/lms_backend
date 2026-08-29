<?php

declare(strict_types=1);

namespace App\Services\Seances\Sync;

use App\Models\Seance;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

/**
 * Archive les séances actives d'UN tenant que KLASSCI n'a pas confirmées durant
 * le cycle de synchronisation courant.
 *
 * ## Issue #582 — balayage par marquage, plus par liste d'identifiants
 *
 * L'ancien contrat recevait `array<institution_id, array<klassci_seance_id>>`,
 * accumulé en mémoire pendant une passe globale complète. Ce contrat est devenu
 * intenable dès lors qu'un cycle s'étale sur PLUSIEURS passes : la liste
 * accumulée est perdue entre deux passes, et n'archiver que sur son contenu
 * partiel supprimerait les séances des enseignants déjà traités.
 *
 * Le critère est donc désormais porté par la donnée elle-même :
 * `synced_at IS NULL OR synced_at < cycle_started_at` — « non confirmée depuis
 * le début de ce cycle » (voir {@see SeanceSyncStamper}).
 *
 * L'appel reste conditionné en amont par {@see TenantArchiveCoordinator}, qui
 * refuse d'archiver un tenant ayant subi une erreur pendant le cycle.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 (≤300 lignes) · §1.6 D (DI strict)
 */
final class StaleSeanceArchiver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * N'archive que dans l'institution donnée (#473) — jamais de balayage global.
     */
    public function archive(int $institutionId, CarbonInterface $cycleStartedAt, SeanceSyncStats $stats): void
    {
        $archivedSeances = Seance::withoutGlobalScope('institution')
            ->where('institution_id', $institutionId)
            ->where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->where(function (Builder $notConfirmed) use ($cycleStartedAt): void {
                $notConfirmed->whereNull('synced_at')
                    ->orWhere('synced_at', '<', $cycleStartedAt);
            })
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
