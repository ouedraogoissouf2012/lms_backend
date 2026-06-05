<?php

declare(strict_types=1);

namespace App\Services\Seances\AutoClose\Rules;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use Carbon\Carbon;
use Psr\Log\LoggerInterface;

/**
 * TeacherDisconnectionRule — extrait verbatim de
 * `AutoCloseEmptySeances::shouldCloseByTeacherRule()`.
 *
 * Règle 1 (priorité 1) du système de fermeture automatique :
 * une séance doit être fermée si l'enseignant est déconnecté depuis au
 * moins {@see self::TEACHER_DISCONNECT_THRESHOLD} minutes.
 *
 * Si aucun enseignant n'a jamais rejoint la séance, la règle ne s'applique
 * pas (elle laisse la main aux autres règles).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 * @see PRODUCTION_STANDARDS.md §1.6 S — Single Responsibility
 */
final class TeacherDisconnectionRule
{
    /** Seuil de temps (en minutes) avant fermeture sur déconnexion enseignant. */
    public const TEACHER_DISCONNECT_THRESHOLD = 5;

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Retourne true si la séance doit être fermée parce que l'enseignant
     * est déconnecté depuis le seuil défini.
     */
    public function appliesTo(Seance $seance, Carbon $now): bool
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
                $this->logger->info('[AutoCloseEmptySeances] Règle 1 activée : Enseignant déconnecté', [
                    'seance_id' => $seance->id,
                    'teacher_user_id' => $teacher->user_id,
                    'minutes_since_disconnect' => $minutesSinceDisconnect,
                ]);
                return true;
            }
        }

        return false;
    }
}
