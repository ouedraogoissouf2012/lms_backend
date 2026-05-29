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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AutoCloseEmptySeances implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Nombre max de tentatives — DB-only, retry 3x sur transient. */
    public int $tries = 3;

    /** Timeout par tentative en secondes — beaucoup de séances à scanner. */
    public int $timeout = 300;

    /**
     * Backoff progressif.
     *
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    // Seuils de temps pour chaque règle
    const TEACHER_DISCONNECT_THRESHOLD = 5;   // minutes
    const ALL_DISCONNECT_THRESHOLD = 10;      // minutes
    const NO_PARTICIPANTS_THRESHOLD = 30;     // minutes
    const HEARTBEAT_SAFETY_BUFFER = 3;        // minutes

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $now = Carbon::now();

        Log::info('[AutoCloseEmptySeances] Job démarré', [
            'timestamp' => $now->toDateTimeString()
        ]);

        // Récupérer toutes les séances actives (sans heure de fin programmée)
        $activeSeances = Seance::where('visio_active', true)
            ->whereNotNull('visio_started_at')
            ->whereNull('visio_ended_at')
            ->with('attendances.user') // Eager loading pour éviter N+1
            ->get();

        Log::info('[AutoCloseEmptySeances] Séances actives trouvées', [
            'count' => $activeSeances->count()
        ]);

        foreach ($activeSeances as $seance) {
            // Refresh pour éviter race conditions
            $seance->refresh();

            // Double vérification que la séance est toujours active
            if (!$seance->visio_active || $seance->visio_ended_at !== null) {
                Log::debug('[AutoCloseEmptySeances] Séance déjà fermée', [
                    'seance_id' => $seance->id
                ]);
                continue;
            }

            // Règle 4 (Protection) : Vérifier la santé du système de heartbeat
            if (!$this->isHeartbeatSystemHealthy($seance, $now)) {
                // Le système de heartbeat semble en panne, ne pas fermer
                continue;
            }

            // Règle 1 (Priorité 1) : Enseignant déconnecté
            if ($this->shouldCloseByTeacherRule($seance, $now)) {
                $this->closeSeance($seance, 'teacher_disconnected', $now);
                continue;
            }

            // Règle 2 (Priorité 2) : Tous déconnectés
            if ($this->shouldCloseByAllDisconnectedRule($seance, $now)) {
                $this->closeSeance($seance, 'all_disconnected', $now);
                continue;
            }

            // Règle 3 (Priorité 3) : Aucun participant
            if ($this->shouldCloseByNoParticipantsRule($seance, $now)) {
                $this->closeSeance($seance, 'no_participants', $now);
                continue;
            }
        }

        Log::info('[AutoCloseEmptySeances] Job terminé');
    }

    /**
     * Règle 1 : Fermer si l'enseignant est déconnecté depuis 5+ minutes
     */
    private function shouldCloseByTeacherRule(Seance $seance, Carbon $now): bool
    {
        // Trouver l'enseignant (via le rôle)
        $teacher = ESBTPAttendance::where('seance_id', $seance->id)
            ->whereHas('user', function ($query) {
                $query->whereIn('role', ['enseignant', 'teacher']);
            })
            ->orderBy('joined_at', 'desc')
            ->first();

        // Si pas d'enseignant trouvé, ne pas appliquer cette règle
        if (!$teacher) {
            return false;
        }

        // Si l'enseignant est connecté, ne pas fermer
        if ($teacher->status === 'connected') {
            return false;
        }

        // Si l'enseignant est déconnecté, vérifier depuis combien de temps
        if ($teacher->left_at) {
            $minutesSinceDisconnect = $teacher->left_at->diffInMinutes($now);

            if ($minutesSinceDisconnect >= self::TEACHER_DISCONNECT_THRESHOLD) {
                Log::info('[AutoCloseEmptySeances] Règle 1 activée : Enseignant déconnecté', [
                    'seance_id' => $seance->id,
                    'teacher_user_id' => $teacher->user_id,
                    'minutes_since_disconnect' => $minutesSinceDisconnect
                ]);
                return true;
            }
        }

        return false;
    }

    /**
     * Règle 2 : Fermer si TOUS les participants sont déconnectés depuis 10+ minutes
     */
    private function shouldCloseByAllDisconnectedRule(Seance $seance, Carbon $now): bool
    {
        // Récupérer tous les participants (excluant les observers)
        $attendances = ESBTPAttendance::where('seance_id', $seance->id)
            ->where('is_observer', false)
            ->get();

        // Si personne n'a jamais rejoint, cette règle ne s'applique pas
        if ($attendances->isEmpty()) {
            return false;
        }

        // Si au moins un participant est connecté, ne pas fermer
        if ($attendances->contains('status', 'connected')) {
            return false;
        }

        // Tous sont déconnectés, trouver le dernier départ
        $lastLeftAt = $attendances
            ->filter(fn($a) => $a->left_at !== null)
            ->max('left_at');

        if (!$lastLeftAt) {
            return false;
        }

        $minutesSinceLastLeft = Carbon::parse($lastLeftAt)->diffInMinutes($now);

        if ($minutesSinceLastLeft >= self::ALL_DISCONNECT_THRESHOLD) {
            Log::info('[AutoCloseEmptySeances] Règle 2 activée : Tous déconnectés', [
                'seance_id' => $seance->id,
                'minutes_since_last_left' => $minutesSinceLastLeft,
                'last_left_at' => $lastLeftAt
            ]);
            return true;
        }

        return false;
    }

    /**
     * Règle 3 : Fermer si personne n'a jamais rejoint ET séance active depuis 30+ minutes
     */
    private function shouldCloseByNoParticipantsRule(Seance $seance, Carbon $now): bool
    {
        // Vérifier si quelqu'un a rejoint (excluant les observers)
        $hasParticipants = ESBTPAttendance::where('seance_id', $seance->id)
            ->where('is_observer', false)
            ->exists();

        // Si quelqu'un a rejoint, cette règle ne s'applique pas
        if ($hasParticipants) {
            return false;
        }

        // Personne n'a rejoint, vérifier depuis combien de temps la séance est active
        $minutesActive = $seance->visio_started_at->diffInMinutes($now);

        if ($minutesActive >= self::NO_PARTICIPANTS_THRESHOLD) {
            Log::info('[AutoCloseEmptySeances] Règle 3 activée : Aucun participant', [
                'seance_id' => $seance->id,
                'minutes_active' => $minutesActive
            ]);
            return true;
        }

        return false;
    }

    /**
     * Règle 4 (Protection) : Vérifier que le système de heartbeat fonctionne
     *
     * Retourne false si le système semble en panne (pour éviter des fermetures prématurées)
     */
    private function isHeartbeatSystemHealthy(Seance $seance, Carbon $now): bool
    {
        // Récupérer les participants marqués comme "connectés"
        $connectedParticipants = ESBTPAttendance::where('seance_id', $seance->id)
            ->where('status', 'connected')
            ->whereNotNull('last_seen_at')
            ->get();

        // Si personne n'est connecté, le heartbeat n'est pas un problème
        if ($connectedParticipants->isEmpty()) {
            return true;
        }

        // Vérifier si au moins un participant a un heartbeat récent
        $hasRecentHeartbeat = $connectedParticipants->contains(function ($attendance) use ($now) {
            $minutesSinceLastSeen = $attendance->last_seen_at->diffInMinutes($now);
            return $minutesSinceLastSeen < self::HEARTBEAT_SAFETY_BUFFER;
        });

        if ($hasRecentHeartbeat) {
            // Au moins un heartbeat récent, le système fonctionne
            return true;
        }

        // Tous les participants "connectés" ont des heartbeats > 3 min
        // Le système de heartbeat semble en panne
        Log::warning('[AutoCloseEmptySeances] Protection heartbeat activée', [
            'seance_id' => $seance->id,
            'reason' => 'Participants connectés mais tous les heartbeats > 3 min',
            'connected_count' => $connectedParticipants->count()
        ]);

        return false;
    }

    /**
     * Fermer une séance et marquer tous les participants restants comme déconnectés
     */
    private function closeSeance(Seance $seance, string $reason, Carbon $now): void
    {
        DB::beginTransaction();

        try {
            // Déterminer le timestamp de fin selon la raison
            $endTimestamp = $this->determineEndTimestamp($seance, $reason, $now);

            // Marquer tous les participants encore connectés comme déconnectés
            $stillConnected = ESBTPAttendance::where('seance_id', $seance->id)
                ->where('status', 'connected')
                ->get();

            foreach ($stillConnected as $attendance) {
                $attendance->status = 'disconnected';
                $attendance->left_at = $endTimestamp;

                if ($attendance->joined_at) {
                    $attendance->duration_minutes = $attendance->joined_at->diffInMinutes($endTimestamp);
                }

                $attendance->save();
            }

            // Fermer la séance
            $seance->visio_active = false;
            $seance->visio_ended_at = $endTimestamp;
            $seance->save();

            DB::commit();

            Log::info('[AutoCloseEmptySeances] Séance fermée avec succès', [
                'seance_id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'reason' => $reason,
                'started_at' => $seance->visio_started_at->toDateTimeString(),
                'ended_at' => $endTimestamp->toDateTimeString(),
                'duration_minutes' => $seance->visio_started_at->diffInMinutes($endTimestamp),
                'participants_disconnected' => $stillConnected->count()
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('[AutoCloseEmptySeances] Erreur lors de la fermeture de la séance', [
                'seance_id' => $seance->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Déterminer le timestamp de fin approprié selon la raison de fermeture
     */
    private function determineEndTimestamp(Seance $seance, string $reason, Carbon $now): Carbon
    {
        switch ($reason) {
            case 'teacher_disconnected':
                // Utiliser le left_at de l'enseignant
                $teacher = ESBTPAttendance::where('seance_id', $seance->id)
                    ->whereHas('user', function ($query) {
                        $query->whereIn('role', ['enseignant', 'teacher']);
                    })
                    ->orderBy('left_at', 'desc')
                    ->first();

                return $teacher && $teacher->left_at ? $teacher->left_at : $now;

            case 'all_disconnected':
                // Utiliser le dernier left_at
                $lastLeftAt = ESBTPAttendance::where('seance_id', $seance->id)
                    ->where('is_observer', false)
                    ->whereNotNull('left_at')
                    ->max('left_at');

                return $lastLeftAt ? Carbon::parse($lastLeftAt) : $now;

            case 'no_participants':
            default:
                // Utiliser maintenant
                return $now;
        }
    }

    /**
     * Gestion des échecs du job
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('[AutoCloseEmptySeances] Job échoué', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
