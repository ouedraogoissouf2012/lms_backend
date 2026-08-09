<?php

namespace App\Jobs;

use App\Jobs\Concerns\InteractsWithDrainBudget;
use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class FinalizeSeanceAttendances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use InteractsWithDrainBudget;

    /** Nombre max de tentatives — DB-only, retry 3x sur transient. */
    public int $tries = 3;

    /** #539 — timeout dur borné au budget de drain (55 s) ; arrêt souple avant. */
    public int $timeout = 55;

    /**
     * Backoff progressif.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    /**
     * Délai après heure_fin pour finaliser les présences (en minutes)
     */
    private const GRACE_PERIOD_MINUTES = 30;

    /**
     * Execute the job.
     *
     * Finalise automatiquement les présences pour les séances terminées:
     * - Marque les participants 'connected' comme 'disconnected'
     * - Calcule la durée de participation
     * - Enregistre left_at comme heure_fin de la séance
     */
    public function handle(LoggerInterface $logger): void
    {
        $now = Carbon::now();
        $finalizedCount = 0;
        $seancesProcessed = 0;
        $startedAt = microtime(true);
        $budgetReached = false;

        $logger->info('[FinalizeSeanceAttendances] Démarrage du job');

        $cutoff = $now->copy()->subMinutes(self::GRACE_PERIOD_MINUTES);

        // visio_ended_at est la source persistée de fin de visio. Traitement PAR
        // LOTS + arrêt souple au budget de drain (#539) : job idempotent — une
        // séance déjà finalisée (plus de participants 'connected') est re-balayée
        // sans effet ; le travail restant reprend au run suivant.
        Seance::where('is_active', true)
            ->whereNotNull('visio_ended_at')
            ->where('visio_ended_at', '<=', $cutoff)
            ->chunkById($this->drainChunkSize, function ($seances) use (&$finalizedCount, &$seancesProcessed, &$budgetReached, $startedAt, $logger) {
                foreach ($seances as $seance) {
                    $seancesProcessed++;
                    $finalizedCount += $this->finalizeSeance($seance, $logger);
                }

                if ($this->drainBudgetExceeded($startedAt)) {
                    $budgetReached = true;

                    return false;
                }

                return true;
            });

        $logger->info('[FinalizeSeanceAttendances] Job terminé', [
            'seances_traitees' => $seancesProcessed,
            'participants_finalises' => $finalizedCount,
            'budget_atteint' => $budgetReached,
        ]);
    }

    /**
     * Finalise les participants encore 'connected' d'une séance terminée.
     * Retourne le nombre de participants finalisés (0 si aucun — la séance
     * a déjà été traitée à un run précédent).
     */
    private function finalizeSeance(Seance $seance, LoggerInterface $logger): int
    {
        $participantsActifs = ESBTPAttendance::where('seance_id', $seance->id)
            ->where('status', 'connected')
            ->get();

        if ($participantsActifs->isEmpty()) {
            return 0;
        }

        $logger->info('[FinalizeSeanceAttendances] Finalisation séance', [
            'seance_id' => $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
            'participants_actifs' => $participantsActifs->count(),
        ]);

        $count = 0;
        foreach ($participantsActifs as $attendance) {
            $dateFin = $seance->visio_ended_at;
            if ($dateFin === null) {
                continue;
            }

            // Si l'étudiant a un last_seen_at avant l'heure de fin, l'utiliser.
            $leftAt = ($attendance->last_seen_at && $attendance->last_seen_at->lt($dateFin))
                ? $attendance->last_seen_at
                : $dateFin;

            $attendance->status = 'disconnected';
            $attendance->left_at = $leftAt;
            if ($attendance->joined_at) {
                $attendance->duration_minutes = (int) $attendance->joined_at->diffInMinutes($leftAt);
            }
            $attendance->save();
            $count++;
        }

        return $count;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Pattern AutoCloseEmptySeances (#209) : failed() est appelée hors
        // container (aucune injection possible) — résolution explicite.
        /** @var LoggerInterface $logger */
        $logger = app(LoggerInterface::class);

        $logger->error('[FinalizeSeanceAttendances] Job échoué', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
