<?php

namespace App\Jobs;

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

    /** Nombre max de tentatives — HTTP KLASSCI peut être instable. */
    public int $tries = 3;

    /** Timeout par tentative en secondes — appels KLASSCI peuvent être lents. */
    public int $timeout = 300;

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

        // Récupérer toutes les séances actives avec un klassci_seance_id
        $seancesLocales = Seance::where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->get();

        $logger->info('📊 [CleanObsoleteSeances] Séances actives à vérifier', [
            'count' => $seancesLocales->count()
        ]);

        if ($seancesLocales->isEmpty()) {
            $logger->info('✅ [CleanObsoleteSeances] Aucune séance à vérifier');
            return;
        }

        // Trouver un token admin/coordinateur valide pour faire les vérifications
        $admin = User::whereNotNull('klassci_token')
            ->whereIn('role', ['coordinateur', 'admin'])
            ->first();

        if (!$admin) {
            $logger->warning('⚠️ [CleanObsoleteSeances] Aucun admin/coordinateur avec token Klassci trouvé');
            return;
        }

        $archivedCount = 0;
        $checkedCount = 0;
        $errorCount = 0;

        foreach ($seancesLocales as $seance) {
            $checkedCount++;
            $klassciSeanceId = $seance->klassci_seance_id;

            try {
                // Essayer de récupérer la séance depuis Klassci
                $response = $klassciService->requestWithUserToken(
                    $admin->klassci_token,
                    "seances/{$klassciSeanceId}",
                    'GET'
                );

                // Si on arrive ici, la séance existe encore dans Klassci
                $logger->debug("✅ Séance #{$klassciSeanceId} existe toujours dans Klassci");

            } catch (\Exception $e) {
                // Si 404 ou autre erreur, la séance n'existe plus
                if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not be found')) {
                    // Archiver la séance locale
                    $seance->is_active = false;
                    $seance->save();

                    $archivedCount++;

                    $logger->info('🗑️ [CleanObsoleteSeances] Séance archivée', [
                        'seance_id' => $seance->id,
                        'klassci_seance_id' => $klassciSeanceId,
                        'matiere' => $seance->matiere_nom,
                        'enseignant' => $seance->enseignant_nom,
                        'raison' => 'N\'existe plus dans Klassci'
                    ]);
                } else {
                    // Autre type d'erreur (réseau, etc.)
                    $errorCount++;
                    $logger->warning('⚠️ [CleanObsoleteSeances] Erreur lors de la vérification', [
                        'seance_id' => $seance->id,
                        'klassci_seance_id' => $klassciSeanceId,
                        'error' => substr($e->getMessage(), 0, 200)
                    ]);
                }
            }
        }

        $logger->info('✅ [CleanObsoleteSeances] Nettoyage terminé', [
            'checked' => $checkedCount,
            'archived' => $archivedCount,
            'errors' => $errorCount,
            'still_active' => $seancesLocales->count() - $archivedCount
        ]);
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
