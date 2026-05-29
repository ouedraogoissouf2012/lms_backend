<?php

namespace App\Jobs;

use App\Models\Seance;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

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

    /** Nombre max de tentatives avant de marquer le job comme failed. */
    public int $tries = 3;

    /** Timeout par tentative en secondes — DB-only, devrait être rapide. */
    public int $timeout = 120;

    /**
     * Backoff entre les tentatives (1 min, puis 5 min).
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * Exécuter le job
     */
    public function handle(): void
    {
        Log::info('🗂️ Début de l\'archivage automatique des séances obsolètes');

        $twoWeeksAgo = now()->subWeeks(2);
        $archivedCount = 0;

        try {
            // Archiver les séances créées il y a > 2 semaines
            // On utilise created_at comme proxy car programmation n'est pas stockée localement
            $seances = Seance::where('is_active', true)
                ->where('created_at', '<', $twoWeeksAgo)
                ->get();

            foreach ($seances as $seance) {
                $seance->update([
                    'is_active' => false,
                    'archived_at' => now(),
                    'archive_reason' => 'trop_ancienne'
                ]);

                $archivedCount++;

                Log::debug('Séance archivée', [
                    'seance_id' => $seance->id,
                    'klassci_seance_id' => $seance->klassci_seance_id,
                    'date' => $seance->programmation['date'] ?? 'N/A',
                    'reason' => 'trop_ancienne'
                ]);
            }

            Log::info('✅ Archivage automatique terminé', [
                'seances_archivees' => $archivedCount,
                'date_limite' => $twoWeeksAgo->toDateString()
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erreur lors de l\'archivage automatique', [
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
        Log::error('Job ArchiveOldSeances failed after all retries', [
            'tries'     => $this->tries,
            'exception' => $exception->getMessage(),
        ]);
    }
}
