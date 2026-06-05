<?php

declare(strict_types=1);

namespace App\Services\Seances\AutoClose;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * HeartbeatHealthChecker — extrait verbatim de
 * `AutoCloseEmptySeances::isHeartbeatSystemHealthy()`.
 *
 * Vérifie la santé du système de heartbeat avant d'appliquer une règle de
 * fermeture automatique. Si tous les participants marqués "connected" ont
 * un `last_seen_at` au-delà du buffer de sécurité, on considère que le
 * système de heartbeat est défaillant et on s'abstient de fermer pour
 * éviter une fermeture prématurée.
 *
 * Cette protection s'applique en amont des règles : si elle échoue, AUCUNE
 * règle de fermeture ne doit être évaluée pour la séance concernée.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 * @see PRODUCTION_STANDARDS.md §1.6 S — Single Responsibility
 */
final class HeartbeatHealthChecker
{
    /**
     * Tolérance maximale (en minutes) entre maintenant et le dernier heartbeat
     * d'un participant connecté avant de considérer le système comme défaillant.
     */
    public const HEARTBEAT_SAFETY_BUFFER = 3;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retourne true si le système de heartbeat semble fonctionnel pour la
     * séance donnée (ou si aucun participant n'est marqué "connected", auquel
     * cas la question ne se pose pas).
     *
     * Retourne false si tous les participants "connected" ont un heartbeat
     * obsolète — auquel cas il faut s'abstenir de fermer la séance.
     */
    public function isHealthy(Seance $seance, Carbon $now): bool
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
        $this->logger->warning('[AutoCloseEmptySeances] Protection heartbeat activée', [
            'seance_id' => $seance->id,
            'reason' => 'Participants connectés mais tous les heartbeats > 3 min',
            'connected_count' => $connectedParticipants->count(),
        ]);

        return false;
    }
}
