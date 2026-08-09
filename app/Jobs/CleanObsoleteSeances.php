<?php

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithDrainBudget;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;

/**
 * Job pour nettoyer les séances obsolètes du LMS
 *
 * Vérifie si les séances locales existent encore dans Klassci.
 * Si une séance n'existe plus dans Klassci, elle est archivée (is_active = false).
 *
 * Ce job doit être exécuté périodiquement (ex: quotidiennement via cron)
 */
class CleanObsoleteSeances implements ShouldQueue
{
    use Queueable;
    use InteractsWithDrainBudget;

    /** Nombre max de tentatives — HTTP KLASSCI peut être instable. */
    public int $tries = 3;

    /** #539 — timeout dur borné au budget de drain (55 s) ; arrêt souple avant. */
    public int $timeout = 55;

    /**
     * Backoff progressif HTTP : 1 min, 5 min, 15 min.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300, 900];

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(KlassciProxyService $klassciService, LoggerInterface $logger): void
    {
        $logger->info('🧹 [CleanObsoleteSeances] Début du nettoyage des séances obsolètes');

        // Trouver un token admin/coordinateur valide pour faire les vérifications.
        $admin = User::whereNotNull('klassci_token')
            ->whereIn('role', ['coordinateur', 'admin'])
            ->first();

        if (! $admin || ! is_string($admin->klassci_token)) {
            $logger->warning('⚠️ [CleanObsoleteSeances] Aucun admin/coordinateur avec token Klassci trouvé');

            return;
        }
        $adminToken = $admin->klassci_token;

        $archivedCount = 0;
        $checkedCount = 0;
        $errorCount = 0;
        $startedAt = microtime(true);
        $budgetReached = false;

        // Vérification PAR LOTS + arrêt souple au budget de drain (#539) : chaque
        // itération fait un appel HTTP KLASSCI, donc un run long tenait le worker.
        // Job idempotent : les séances encore présentes restent is_active=true et
        // seront re-vérifiées au run suivant, où le travail restant reprend.
        Seance::where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->chunkById($this->drainChunkSize, function ($seances) use ($adminToken, $klassciService, $logger, &$archivedCount, &$checkedCount, &$errorCount, &$budgetReached, $startedAt) {
                foreach ($seances as $seance) {
                    $checkedCount++;
                    $result = $this->checkAndArchiveSeance($seance, $adminToken, $klassciService, $logger);
                    if ($result === 'archived') {
                        $archivedCount++;
                    } elseif ($result === 'error') {
                        $errorCount++;
                    }
                }

                if ($this->drainBudgetExceeded($startedAt)) {
                    $budgetReached = true;

                    return false;
                }

                return true;
            });

        $logger->info('✅ [CleanObsoleteSeances] Nettoyage terminé', [
            'checked' => $checkedCount,
            'archived' => $archivedCount,
            'errors' => $errorCount,
            'budget_atteint' => $budgetReached,
        ]);
    }

    /**
     * Vérifie une séance locale contre KLASSCI ; l'archive si elle a disparu (404).
     * Retourne 'archived', 'exists' ou 'error'.
     */
    private function checkAndArchiveSeance(
        Seance $seance,
        string $adminToken,
        KlassciProxyService $klassciService,
        LoggerInterface $logger,
    ): string {
        $klassciSeanceId = $seance->klassci_seance_id;

        try {
            $klassciService->requestWithUserToken($adminToken, "seances/{$klassciSeanceId}", 'GET');

            return 'exists';
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not be found')) {
                $seance->is_active = false;
                $seance->save();

                $logger->info('🗑️ [CleanObsoleteSeances] Séance archivée', [
                    'seance_id' => $seance->id,
                    'klassci_seance_id' => $klassciSeanceId,
                    'raison' => 'N\'existe plus dans Klassci',
                ]);

                return 'archived';
            }

            $logger->warning('⚠️ [CleanObsoleteSeances] Erreur lors de la vérification', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $klassciSeanceId,
                'error' => substr($e->getMessage(), 0, 200),
            ]);

            return 'error';
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

        $logger->error('[CleanObsoleteSeances] Job failed after all retries', [
            'tries'     => $this->tries,
            'exception' => $exception->getMessage(),
        ]);
    }
}
