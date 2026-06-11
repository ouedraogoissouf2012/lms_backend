<?php

namespace App\Jobs;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Psr\Log\LoggerInterface;
use Carbon\Carbon;

/**
 * Job pour archiver les évaluations passées non effectuées
 *
 * Pour alléger la vue des étudiants, on archive (soft delete) les évaluations:
 * - Terminées depuis plus de X jours
 * - Que l'étudiant n'a jamais commencées
 *
 * Les évaluations avec soumissions sont TOUJOURS conservées (pour l'historique)
 *
 * Ce job s'exécute quotidiennement
 */
class CleanOldEvaluations implements ShouldQueue
{
    use Queueable;

    /** Nombre max de tentatives — DB-only. */
    public int $tries = 3;

    /** Timeout par tentative en secondes. */
    public int $timeout = 180;

    /**
     * Backoff progressif.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * Nombre de jours après date_evaluation pour considérer une évaluation comme "passée"
     */
    private const DAYS_AFTER_EVALUATION = 7;

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
    public function handle(LoggerInterface $logger): void
    {
        $logger->info('🧹 [CleanOldEvaluations] Début du nettoyage des évaluations passées');

        // Date limite: évaluations terminées depuis plus de X jours
        $cutoffDate = Carbon::now()->subDays(self::DAYS_AFTER_EVALUATION);

        // Récupérer les évaluations candidates à l'archivage
        $oldEvaluations = Evaluation::where('is_published', true)
            ->where(function ($query) use ($cutoffDate) {
                // Status terminée
                $query->where('status', 'terminee')
                    // OU date passée + durée écoulée
                    ->orWhere(function ($q) use ($cutoffDate) {
                        $q->where('date_evaluation', '<', $cutoffDate)
                          ->whereIn('status', ['en_cours', 'planifiee']);
                    });
            })
            ->whereNull('deleted_at') // Pas déjà archivées
            ->get();

        $logger->info('📊 [CleanOldEvaluations] Évaluations candidates', [
            'count' => $oldEvaluations->count(),
            'cutoff_date' => $cutoffDate->toDateTimeString()
        ]);

        if ($oldEvaluations->isEmpty()) {
            $logger->info('✅ [CleanOldEvaluations] Aucune évaluation à archiver');
            return;
        }

        $archivedCount = 0;
        $keptCount = 0;

        foreach ($oldEvaluations as $evaluation) {
            // Compter combien d'étudiants ont soumis
            $submissionsCount = EvaluationSubmission::where('evaluation_id', $evaluation->id)
                ->whereIn('status', ['soumis', 'corrige'])
                ->count();

            // Si PERSONNE n'a fait l'évaluation, on peut l'archiver
            if ($submissionsCount === 0) {
                $evaluation->delete(); // Soft delete

                $archivedCount++;

                $logger->info('🗑️ [CleanOldEvaluations] Évaluation archivée', [
                    'evaluation_id' => $evaluation->id,
                    'titre' => $evaluation->titre,
                    'date_evaluation' => $evaluation->date_evaluation,
                    'status' => $evaluation->status,
                    'raison' => 'Aucune soumission, passée depuis ' . self::DAYS_AFTER_EVALUATION . ' jours'
                ]);
            } else {
                // Garder si au moins 1 soumission
                $keptCount++;

                $logger->debug('✅ [CleanOldEvaluations] Évaluation conservée', [
                    'evaluation_id' => $evaluation->id,
                    'titre' => $evaluation->titre,
                    'submissions_count' => $submissionsCount,
                    'raison' => 'A des soumissions'
                ]);
            }
        }

        $logger->info('✅ [CleanOldEvaluations] Nettoyage terminé', [
            'checked' => $oldEvaluations->count(),
            'archived' => $archivedCount,
            'kept' => $keptCount,
            'cutoff_date' => $cutoffDate->toDateTimeString()
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

        $logger->error('[CleanOldEvaluations] Job failed after all retries', [
            'tries'     => $this->tries,
            'exception' => $exception->getMessage(),
        ]);
    }
}
