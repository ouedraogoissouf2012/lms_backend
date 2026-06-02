<?php

declare(strict_types=1);

namespace App\Services\Visio;

/**
 * VisioParticipantsStatsBuilder — extrait verbatim de
 * `LMSVisioParticipantController::getVisioParticipants()` (bloc
 * « 7. Calculer les statistiques globales »).
 *
 * Calcule les compteurs agrégés (présents/absents/retards/départs
 * anticipés/complets) et les moyennes (% présence, durée minutes)
 * à partir de la liste unifiée déjà enrichie.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 */
final class VisioParticipantsStatsBuilder
{
    /**
     * @param array<int, array<string, mixed>> $unifiedList
     *
     * @return array<string, mixed>
     */
    public function build(
        array $unifiedList,
        int|float $seanceDurationMinutes,
        ?string $visioStatus,
    ): array {
        // Compter seulement les participants ENCORE CONNECTÉS (pas ceux qui ont quitté)
        $presentCount = count(array_filter(
            $unifiedList,
            static fn ($s): bool => (bool) ($s['is_connected'] ?? false),
        ));
        $absentCount = count($unifiedList) - $presentCount;
        $lateCount = count(array_filter(
            $unifiedList,
            static fn ($s): bool => (bool) ($s['is_late'] ?? false),
        ));
        $leftEarlyCount = count(array_filter(
            $unifiedList,
            static fn ($s): bool => (bool) ($s['left_early'] ?? false),
        ));
        $completePresenceCount = count(array_filter(
            $unifiedList,
            static fn ($s): bool => ($s['percentage'] ?? 0) === 100,
        ));

        // Calculer le pourcentage moyen de présence
        $totalPercentage = array_reduce(
            $unifiedList,
            static fn (int|float $sum, array $s): int|float => $sum + ($s['percentage'] ?? 0),
            0,
        );
        $averagePercentage = count($unifiedList) > 0
            ? round($totalPercentage / count($unifiedList))
            : 0;

        // Calculer la durée moyenne
        $totalDuration = array_reduce(
            $unifiedList,
            static fn (int|float $sum, array $s): int|float => $sum + ($s['duration_minutes'] ?? 0),
            0,
        );
        $averageDuration = $presentCount > 0 ? round($totalDuration / $presentCount) : 0;

        return [
            'total_students' => count($unifiedList),
            'present_count' => $presentCount,
            'absent_count' => $absentCount,
            'presence_rate' => count($unifiedList) > 0
                ? round(($presentCount / count($unifiedList)) * 100)
                : 0,
            'complete_presence_count' => $completePresenceCount,
            'late_count' => $lateCount,
            'left_early_count' => $leftEarlyCount,
            'average_percentage' => $averagePercentage,
            'average_duration_minutes' => $averageDuration,
            'seance_duration_minutes' => $seanceDurationMinutes,
            'visio_status' => $visioStatus, // Ajouter pour conditionner affichage %
        ];
    }
}
