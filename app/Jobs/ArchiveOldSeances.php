<?php

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithDrainBudget;
use App\Models\Seance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

/**
 * Job pour archiver automatiquement les séances obsolètes
 *
 * Critères d'archivage:
 * - Séances > 2 semaines après leur date
 * - Séances qui n'existent plus dans Klassci (détecté par SyncKlassciSeances)
 */
class ArchiveOldSeances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithDrainBudget;

    /** Nombre max de tentatives avant de marquer le job comme failed. */
    public int $tries = 3;

    /** #539 — timeout dur borné au budget de drain (55 s) ; arrêt souple avant. */
    public int $timeout = 55;

    /**
     * Backoff entre les tentatives (1 min, puis 5 min).
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * Exécuter le job
     */
    public function handle(LoggerInterface $logger): void
    {
        $logger->info('🗂️ Début de l\'archivage automatique des séances obsolètes');

        $twoWeeksAgo = now()->subWeeks(2);
        $archivedCount = 0;
        $startedAt = microtime(true);
        $budgetReached = false;

        try {
            // Archiver PAR LOTS les séances créées il y a > 2 semaines (created_at
            // comme proxy, la programmation n'étant pas stockée localement).
            // chunkById utilise un curseur id (pas offset) : rester correct malgré
            // la mutation de is_active. Arrêt souple au budget de drain (#539) —
            // reprise au run suivant (idempotent : les séances archivées sortent
            // du filtre is_active).
            Seance::where('is_active', true)
                ->where('created_at', '<', $twoWeeksAgo)
                ->chunkById($this->drainChunkSize, function ($seances) use (&$archivedCount, &$budgetReached, $startedAt) {
                    foreach ($seances as $seance) {
                        $seance->update([
                            'is_active' => false,
                            'archived_at' => now(),
                            'archive_reason' => 'trop_ancienne',
                        ]);
                        $archivedCount++;
                    }

                    if ($this->drainBudgetExceeded($startedAt)) {
                        $budgetReached = true;

                        return false;
                    }

                    return true;
                });

            $logger->info('✅ Archivage automatique terminé', [
                'seances_archivees' => $archivedCount,
                'date_limite' => $twoWeeksAgo->toDateString(),
                'budget_atteint' => $budgetReached,
            ]);

        } catch (\Exception $e) {
            $logger->error('❌ Erreur lors de l\'archivage automatique', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Appelé quand le job a échoué toutes ses tentatives.
     * Permet d'avoir un log structuré dédié distinct du log de l'exception qui a déclenché l'échec.
     */
    public function failed(\Throwable $exception): void
    {
        // Pattern AutoCloseEmptySeances (#209) : failed() est appelée hors
        // container (aucune injection possible) — résolution explicite.
        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        $logger->error('Job ArchiveOldSeances failed after all retries', [
            'tries'     => $this->tries,
            'exception' => $exception->getMessage(),
        ]);
    }
}
