<?php

namespace App\Console\Commands;

use App\Models\ESBTPAttendance;
use App\Services\Visio\AttendanceLifecycleService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DetectInactiveParticipants extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'visio:detect-inactive {--timeout=2 : Timeout en minutes pour considérer un participant inactif}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Détecter et déconnecter automatiquement les participants inactifs dans les visioconférences';

    /**
     * Execute the console command.
     */
    public function handle(AttendanceLifecycleService $lifecycle): int
    {
        $timeoutMinutes = (int) $this->option('timeout');
        $threshold = Carbon::now()->subMinutes($timeoutMinutes);

        $this->info("🔍 Détection des participants inactifs (timeout: {$timeoutMinutes} minutes)...");

        // Trouver tous les participants marqués comme "connectés" mais sans heartbeat récent
        $inactiveParticipants = ESBTPAttendance::where('status', 'connected')
            ->where(function ($query) use ($threshold) {
                $query->where('last_seen_at', '<', $threshold)
                    ->orWhere(function ($q) use ($threshold) {
                        $q->whereNull('last_seen_at')
                            ->where('joined_at', '<', $threshold);
                    });
            })
            ->get();

        if ($inactiveParticipants->isEmpty()) {
            $this->info('✅ Aucun participant inactif détecté');

            return Command::SUCCESS;
        }

        $disconnectedCount = 0;

        foreach ($inactiveParticipants as $attendance) {
            $this->warn("⚠️  Participant inactif détecté: {$attendance->nom} {$attendance->prenom} (Séance ID: {$attendance->seance_id})");

            // Marquer comme déconnecté au dernier signal réel, pas à l'heure du batch.
            $lifecycle->disconnect($attendance, $attendance->last_seen_at ?? $attendance->joined_at);
            $disconnectedCount++;

            Log::info('Participant auto-déconnecté pour inactivité', [
                'attendance_id' => $attendance->id,
                'seance_id' => $attendance->seance_id,
                'user_id' => $attendance->user_id,
                'last_seen_at' => $attendance->last_seen_at?->toDateTimeString(),
                'duration_minutes' => $attendance->duration_minutes,
            ]);
        }

        $this->info("✅ {$disconnectedCount} participant(s) déconnecté(s) automatiquement");

        return Command::SUCCESS;
    }
}
