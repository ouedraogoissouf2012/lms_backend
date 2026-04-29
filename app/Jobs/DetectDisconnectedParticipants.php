<?php

namespace App\Jobs;

use App\Models\ESBTPAttendance;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DetectDisconnectedParticipants implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Durée d'inactivité avant de considérer un participant comme déconnecté (en minutes)
     */
    private const INACTIVITY_THRESHOLD_MINUTES = 5;

    /**
     * Execute the job.
     *
     * Détecte les participants qui n'ont pas envoyé de heartbeat depuis INACTIVITY_THRESHOLD
     * et les marque automatiquement comme déconnectés.
     */
    public function handle(): void
    {
        $now = Carbon::now();
        $disconnectedCount = 0;

        Log::info('[DetectDisconnectedParticipants] Démarrage du job');

        // Récupérer tous les participants marqués 'connected'
        $participantsConnectes = ESBTPAttendance::where('status', 'connected')
            ->whereNotNull('last_seen_at') // Doit avoir au moins un heartbeat
            ->get();

        Log::info('[DetectDisconnectedParticipants] Participants à vérifier', [
            'count' => $participantsConnectes->count()
        ]);

        foreach ($participantsConnectes as $attendance) {
            // Vérifier si le dernier heartbeat est trop ancien
            $minutesSinceLastSeen = abs($now->diffInMinutes($attendance->last_seen_at));

            if ($minutesSinceLastSeen >= self::INACTIVITY_THRESHOLD_MINUTES) {
                // Marquer comme déconnecté
                $attendance->status = 'disconnected';
                $attendance->left_at = $attendance->last_seen_at; // Utiliser le dernier heartbeat comme heure de déconnexion

                // Calculer la durée
                if ($attendance->joined_at) {
                    $attendance->duration_minutes = $attendance->joined_at->diffInMinutes($attendance->left_at);
                }

                $attendance->save();

                $disconnectedCount++;

                Log::info('[DetectDisconnectedParticipants] Participant déconnecté (inactivité)', [
                    'user_id' => $attendance->user_id,
                    'seance_id' => $attendance->seance_id,
                    'last_seen_at' => $attendance->last_seen_at->format('Y-m-d H:i:s'),
                    'minutes_inactif' => $minutesSinceLastSeen,
                    'duration_minutes' => $attendance->duration_minutes
                ]);
            }
        }

        // Gérer aussi les participants 'connected' sans last_seen_at
        // (cas où l'étudiant a rejoint mais n'a jamais envoyé de heartbeat)
        $participantsSansHeartbeat = ESBTPAttendance::where('status', 'connected')
            ->whereNull('last_seen_at')
            ->get();

        foreach ($participantsSansHeartbeat as $attendance) {
            // Si joined_at > INACTIVITY_THRESHOLD, marquer comme déconnecté
            if ($attendance->joined_at) {
                $minutesSinceJoined = abs($now->diffInMinutes($attendance->joined_at));

                if ($minutesSinceJoined >= self::INACTIVITY_THRESHOLD_MINUTES) {
                    $attendance->status = 'disconnected';
                    $attendance->left_at = $attendance->joined_at; // Déconnecté immédiatement
                    $attendance->duration_minutes = 0; // Pas de participation réelle

                    $attendance->save();

                    $disconnectedCount++;

                    Log::info('[DetectDisconnectedParticipants] Participant déconnecté (sans heartbeat)', [
                        'user_id' => $attendance->user_id,
                        'seance_id' => $attendance->seance_id,
                        'joined_at' => $attendance->joined_at->format('Y-m-d H:i:s'),
                        'minutes_depuis_join' => $minutesSinceJoined
                    ]);
                }
            }
        }

        Log::info('[DetectDisconnectedParticipants] Job terminé', [
            'participants_deconnectes' => $disconnectedCount
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[DetectDisconnectedParticipants] Job échoué', [
            'exception_class' => get_class($exception),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
