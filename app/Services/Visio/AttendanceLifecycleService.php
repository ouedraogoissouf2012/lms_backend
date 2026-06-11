<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\ESBTPAttendance;
use Psr\Log\LoggerInterface;

/**
 * Cycle de vie d'une participation visio (ESBTPAttendance) — ex-méthodes du
 * modèle (H2 audit, §5) : déconnexion (+ calcul durée), heartbeat, timeout
 * automatique des inactifs.
 *
 * DI strict §1.6 : LoggerInterface PSR-3 injecté (remplace la Facade `\Log::`
 * de l'ancien `ESBTPAttendance::disconnectInactiveParticipants`).
 */
final class AttendanceLifecycleService
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * Marque le participant déconnecté + calcule `duration_minutes`.
     */
    public function disconnect(ESBTPAttendance $attendance): void
    {
        $attendance->status = 'disconnected';
        $attendance->left_at = now();

        if ($attendance->joined_at) {
            $attendance->duration_minutes = $attendance->joined_at->diffInMinutes($attendance->left_at);
        }

        $attendance->save();
    }

    /**
     * Met à jour le heartbeat (keep-alive).
     */
    public function heartbeat(ESBTPAttendance $attendance): void
    {
        $attendance->last_seen_at = now();
        $attendance->save();
    }

    /**
     * Déconnecte automatiquement les participants inactifs (heartbeat trop
     * ancien, ou jamais reçu avec `joined_at` trop ancien).
     *
     * @return int Nombre de participants déconnectés.
     */
    public function disconnectInactive(int $timeoutMinutes = 3): int
    {
        $timeoutThreshold = now()->subMinutes($timeoutMinutes);

        $inactiveParticipants = ESBTPAttendance::where('status', 'connected')
            ->where(function ($query) use ($timeoutThreshold) {
                $query->where('last_seen_at', '<', $timeoutThreshold)
                      ->orWhere(function ($q) use ($timeoutThreshold) {
                          $q->whereNull('last_seen_at')
                            ->where('joined_at', '<', $timeoutThreshold);
                      });
            })
            ->get();

        $disconnectedCount = 0;

        foreach ($inactiveParticipants as $participant) {
            $this->logger->info('Timeout détecté - Déconnexion automatique', [
                'user_id' => $participant->user_id,
                'seance_id' => $participant->seance_id,
                'last_seen_at' => $participant->last_seen_at?->toDateTimeString(),
                'joined_at' => $participant->joined_at?->toDateTimeString(),
            ]);

            $this->disconnect($participant);
            $disconnectedCount++;
        }

        return $disconnectedCount;
    }
}
