<?php

namespace App\Jobs;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FinalizeSeanceAttendances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

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
    public function handle(): void
    {
        $now = Carbon::now();
        $finalizedCount = 0;

        Log::info('[FinalizeSeanceAttendances] Démarrage du job');

        // Récupérer toutes les séances actives qui sont terminées depuis > GRACE_PERIOD
        $seancesTerminees = Seance::where('is_active', true)
            ->whereNotNull('heure_fin')
            ->whereNotNull('date_seance')
            ->get()
            ->filter(function ($seance) use ($now) {
                // Construire le datetime de fin
                $dateFin = Carbon::parse($seance->date_seance . ' ' . $seance->heure_fin);
                $graceDeadline = $dateFin->copy()->addMinutes(self::GRACE_PERIOD_MINUTES);

                // Ne traiter que les séances dont le délai de grâce est dépassé
                return $graceDeadline->isPast();
            });

        Log::info('[FinalizeSeanceAttendances] Séances à traiter', [
            'count' => $seancesTerminees->count()
        ]);

        foreach ($seancesTerminees as $seance) {
            // Récupérer tous les participants encore marqués 'connected'
            $participantsActifs = ESBTPAttendance::where('seance_id', $seance->id)
                ->where('status', 'connected')
                ->get();

            if ($participantsActifs->isEmpty()) {
                continue;
            }

            Log::info('[FinalizeSeanceAttendances] Finalisation séance', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'titre' => $seance->titre,
                'participants_actifs' => $participantsActifs->count()
            ]);

            foreach ($participantsActifs as $attendance) {
                // Calculer left_at: soit heure_fin de la séance, soit last_seen_at
                $dateFin = Carbon::parse($seance->date_seance . ' ' . $seance->heure_fin);

                // Si l'étudiant a un last_seen_at avant l'heure de fin, utiliser last_seen_at
                if ($attendance->last_seen_at && $attendance->last_seen_at->lt($dateFin)) {
                    $leftAt = $attendance->last_seen_at;
                } else {
                    $leftAt = $dateFin;
                }

                // Marquer comme déconnecté
                $attendance->status = 'disconnected';
                $attendance->left_at = $leftAt;

                // Calculer la durée si joined_at existe
                if ($attendance->joined_at) {
                    $attendance->duration_minutes = $attendance->joined_at->diffInMinutes($leftAt);
                }

                $attendance->save();

                $finalizedCount++;

                Log::info('[FinalizeSeanceAttendances] Participant finalisé', [
                    'user_id' => $attendance->user_id,
                    'joined_at' => $attendance->joined_at?->format('Y-m-d H:i:s'),
                    'left_at' => $leftAt->format('Y-m-d H:i:s'),
                    'duration_minutes' => $attendance->duration_minutes
                ]);
            }
        }

        Log::info('[FinalizeSeanceAttendances] Job terminé', [
            'seances_traitees' => $seancesTerminees->count(),
            'participants_finalises' => $finalizedCount
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[FinalizeSeanceAttendances] Job échoué', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
