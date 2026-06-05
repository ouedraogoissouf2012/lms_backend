<?php

declare(strict_types=1);

namespace App\Services\Seances\AutoClose\Rules;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * NoParticipantsRule — extrait verbatim de
 * `AutoCloseEmptySeances::shouldCloseByNoParticipantsRule()`.
 *
 * Règle 3 (priorité 3) du système de fermeture automatique :
 * une séance doit être fermée si personne (hors observers) n'a jamais
 * rejoint ET que la séance est active depuis au moins
 * {@see self::NO_PARTICIPANTS_THRESHOLD} minutes.
 *
 * Note: la santé du système de heartbeat est vérifiée en amont par
 * {@see \App\Services\Seances\AutoClose\HeartbeatHealthChecker} — cette
 * règle suppose que le heartbeat est fonctionnel quand on l'évalue.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 * @see PRODUCTION_STANDARDS.md §1.6 S — Single Responsibility
 */
final class NoParticipantsRule
{
    /** Seuil de temps (en minutes) avant fermeture si aucun participant. */
    public const NO_PARTICIPANTS_THRESHOLD = 30;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retourne true si la séance doit être fermée parce qu'aucun participant
     * (hors observers) n'a jamais rejoint depuis le seuil défini.
     */
    public function appliesTo(Seance $seance, Carbon $now): bool
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
            $this->logger->info('[AutoCloseEmptySeances] Règle 3 activée : Aucun participant', [
                'seance_id' => $seance->id,
                'minutes_active' => $minutesActive,
            ]);
            return true;
        }

        return false;
    }
}
