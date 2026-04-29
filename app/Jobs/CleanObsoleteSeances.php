<?php

namespace App\Jobs;

use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
    public function handle(KlassciProxyService $klassciService): void
    {
        Log::info('🧹 [CleanObsoleteSeances] Début du nettoyage des séances obsolètes');

        // Récupérer toutes les séances actives avec un klassci_seance_id
        $seancesLocales = Seance::where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->get();

        Log::info('📊 [CleanObsoleteSeances] Séances actives à vérifier', [
            'count' => $seancesLocales->count()
        ]);

        if ($seancesLocales->isEmpty()) {
            Log::info('✅ [CleanObsoleteSeances] Aucune séance à vérifier');
            return;
        }

        // Trouver un token admin/coordinateur valide pour faire les vérifications
        $admin = User::whereNotNull('klassci_token')
            ->whereIn('role', ['coordinateur', 'admin'])
            ->first();

        if (!$admin) {
            Log::warning('⚠️ [CleanObsoleteSeances] Aucun admin/coordinateur avec token Klassci trouvé');
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
                Log::debug("✅ Séance #{$klassciSeanceId} existe toujours dans Klassci");

            } catch (\Exception $e) {
                // Si 404 ou autre erreur, la séance n'existe plus
                if (str_contains($e->getMessage(), '404') || str_contains($e->getMessage(), 'not be found')) {
                    // Archiver la séance locale
                    $seance->is_active = false;
                    $seance->save();

                    $archivedCount++;

                    Log::info('🗑️ [CleanObsoleteSeances] Séance archivée', [
                        'seance_id' => $seance->id,
                        'klassci_seance_id' => $klassciSeanceId,
                        'matiere' => $seance->matiere_nom,
                        'enseignant' => $seance->enseignant_nom,
                        'raison' => 'N\'existe plus dans Klassci'
                    ]);
                } else {
                    // Autre type d'erreur (réseau, etc.)
                    $errorCount++;
                    Log::warning('⚠️ [CleanObsoleteSeances] Erreur lors de la vérification', [
                        'seance_id' => $seance->id,
                        'klassci_seance_id' => $klassciSeanceId,
                        'exception_class' => get_class($e)
                    ]);
                }
            }
        }

        Log::info('✅ [CleanObsoleteSeances] Nettoyage terminé', [
            'checked' => $checkedCount,
            'archived' => $archivedCount,
            'errors' => $errorCount,
            'still_active' => $seancesLocales->count() - $archivedCount
        ]);
    }
}
