<?php

namespace App\Jobs;

use App\Services\Seances\Sync\KlassciSeancesSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SyncKlassciSeances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Nombre max de tentatives — HTTP KLASSCI instable, mais sync = idempotent. */
    public int $tries = 3;

    /**
     * #539 — timeout dur borné au budget de drain (55 s). La sync s'arrête
     * elle-même avant (budget souple côté service) et reprend au drain suivant
     * (idempotente), pour ne pas monopoliser le worker au détriment des jobs `high`.
     */
    public int $timeout = 55;

    /**
     * Backoff HTTP progressif : 1 min, 5 min, 15 min.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Execute the job.
     */
    public function handle(KlassciSeancesSyncService $syncService, LoggerInterface $logger): void
    {
        $logger->info('Job SyncKlassciSeances démarré');

        try {
            $stats = $syncService->sync();

            $logger->info('Job SyncKlassciSeances terminé', $stats->toArray());
        } catch (\Exception $e) {
            $logger->error('Erreur fatale dans job SyncKlassciSeances', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Re-throw pour permettre au retry mechanism de fonctionner.
            throw $e;
        }
    }

    /**
     * Job échoué après toutes les tentatives.
     */
    public function failed(\Throwable $exception): void
    {
        // Pattern AutoCloseEmptySeances (#209) : failed() est appelée hors
        // container (aucune injection possible) — résolution explicite.
        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        $logger->error('[SyncKlassciSeances] Job failed after all retries', [
            'tries' => $this->tries,
            'exception' => $exception->getMessage(),
        ]);
    }
}
