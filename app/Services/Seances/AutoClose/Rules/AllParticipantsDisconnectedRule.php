<?php

declare(strict_types=1);

namespace App\Services\Seances\AutoClose\Rules;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * AllParticipantsDisconnectedRule — extrait verbatim de
 * `AutoCloseEmptySeances::shouldCloseByAllDisconnectedRule()`.
 *
 * Règle 2 (priorité 2) du système de fermeture automatique :
 * une séance doit être fermée si TOUS les participants (hors observers)
 * sont déconnectés depuis au moins
 * {@see self::ALL_DISCONNECT_THRESHOLD} minutes.
 *
 * Si personne n'a jamais rejoint, la règle ne s'applique pas
 * (la règle "no participants" prendra le relais).
 *
 * Note: la santé du système de heartbeat est vérifiée en amont par
 * {@see \App\Services\Seances\AutoClose\HeartbeatHealthChecker} — cette
 * règle suppose que le heartbeat est fonctionnel quand on l'évalue.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 * @see PRODUCTION_STANDARDS.md §1.6 S — Single Responsibility
 */
final class AllParticipantsDisconnectedRule
{
    /** Seuil de temps (en minutes) avant fermeture si tous déconnectés. */
    public const ALL_DISCONNECT_THRESHOLD = 10;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retourne true si la séance doit être fermée parce que tous les
     * participants (hors observers) sont déconnectés depuis le seuil défini.
     */
    public function appliesTo(Seance $seance, Carbon $now): bool
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
            $this->logger->info('[AutoCloseEmptySeances] Règle 2 activée : Tous déconnectés', [
                'seance_id' => $seance->id,
                'minutes_since_last_left' => $minutesSinceLastLeft,
                'last_left_at' => $lastLeftAt,
            ]);
            return true;
        }

        return false;
    }
}
