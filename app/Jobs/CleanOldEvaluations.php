<?php

namespace App\Jobs;

use App\Enums\EvaluationStatus;
use App\Enums\EvaluationSubmissionStatus;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Job pour archiver les évaluations passées non effectuées
 *
 * Soft-delete les évaluations dont date_evaluation a plus de 7 jours
 * (planifiee, en_cours ou terminee) ET sans aucune copie en_cours/soumis/corrige.
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
        $cutoffDate = Carbon::now()->subDays(self::DAYS_AFTER_EVALUATION);
        $oldEvaluations = $this->stalePublishedEvaluations($cutoffDate);

        $logger->info('📊 [CleanOldEvaluations] Évaluations candidates', [
            'count' => $oldEvaluations->count(),
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ]);

        if ($oldEvaluations->isEmpty()) {
            $logger->info('✅ [CleanOldEvaluations] Aucune évaluation à archiver');

            return;
        }

        [$archivedCount, $keptCount] = $this->archiveOrphans($oldEvaluations, $logger);

        $logger->info('✅ [CleanOldEvaluations] Nettoyage terminé', [
            'checked' => $oldEvaluations->count(),
            'archived' => $archivedCount,
            'kept' => $keptCount,
            'cutoff_date' => $cutoffDate->toDateTimeString(),
        ]);
    }

    /**
     * @return Collection<int, Evaluation>
     */
    private function stalePublishedEvaluations(Carbon $cutoffDate): Collection
    {
        return Evaluation::query()
            ->where('is_published', true)
            ->where('date_evaluation', '<', $cutoffDate)
            ->whereIn('status', [
                EvaluationStatus::Terminee->value,
                EvaluationStatus::EnCours->value,
                EvaluationStatus::Planifiee->value,
            ])
            ->get();
    }

    /**
     * @param  Collection<int, Evaluation>  $evaluations
     * @return array{0: int, 1: int}
     */
    private function archiveOrphans(Collection $evaluations, LoggerInterface $logger): array
    {
        $archivedCount = 0;
        $keptCount = 0;

        foreach ($evaluations as $evaluation) {
            if ($this->hasActiveCopy($evaluation)) {
                $keptCount++;
                continue;
            }

            $evaluation->delete();
            $archivedCount++;
            $logger->info('🗑️ [CleanOldEvaluations] Évaluation archivée', [
                'evaluation_id' => $evaluation->id,
            ]);
        }

        return [$archivedCount, $keptCount];
    }

    private function hasActiveCopy(Evaluation $evaluation): bool
    {
        return EvaluationSubmission::query()
            ->where('evaluation_id', $evaluation->id)
            ->whereIn('status', [
                EvaluationSubmissionStatus::EnCours->value,
                EvaluationSubmissionStatus::Soumis->value,
                EvaluationSubmissionStatus::Corrige->value,
            ])
            ->exists();
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
