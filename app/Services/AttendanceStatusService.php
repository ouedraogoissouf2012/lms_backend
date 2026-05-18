<?php

namespace App\Services;

use DateTimeImmutable;

/**
 * AttendanceStatusService — derive an attendance status (label/icon) from
 * participation metrics.
 *
 * Created during the `LMSDataController` god-object split (spec:
 * `.claude/specs/lms-data-controller-split/`, PR G).
 *
 * ## Why this service exists
 *
 * The legacy `determineAttendanceStatus()` was a private helper inside the
 * (now-deleted) `LMSDataController`, used by `getVisioParticipants` (visio domain).
 * `LMSVisioController` (PR I) and `LMSAttendancesController` (PR H) both needed
 * this logic — duplicating it would violate DRY, and exposing it through one
 * controller would couple the other to it. A shared service is the right home.
 *
 * ## Pure function (no I/O, no DB)
 *
 * This service has no constructor dependencies. It is a pure computation:
 * given participation percentage + timestamps, it returns the labelled status.
 * Trivially unit-testable, no mocks required.
 *
 * @see \App\Http\Controllers\API\LMS\LMSVisioController::getVisioParticipants (sole consumer)
 * @see .claude/specs/lms-data-controller-split/design.md §2.3
 */
final class AttendanceStatusService
{
    /**
     * Tolerance, in minutes, before a join counts as "late" or a leave counts
     * as "early-departure". 5 minutes is the legacy threshold (preserved verbatim).
     */
    public const RETARD_SEUIL_MINUTES = 5;

    /**
     * Determine the attendance status of a participant.
     *
     * @param int|float|null $percentage   Participation percentage (0-100), or null.
     * @param string|null    $joinedAt     ISO-8601 timestamp when participant joined.
     * @param string|null    $leftAt       ISO-8601 timestamp when participant left.
     * @param string|null    $heureDebut   Scheduled séance start (ISO-8601).
     * @param string|null    $heureFin     Scheduled séance end (ISO-8601).
     * @param string|null    $visioStatus  Visio status ('active', 'terminee', etc.) —
     *                                     affects whether percentage appears in label.
     *
     * @return array{label: string, icon: string, is_late: bool, left_early: bool}
     */
    public function determine(
        int|float|null $percentage,
        ?string $joinedAt,
        ?string $leftAt,
        ?string $heureDebut,
        ?string $heureFin,
        ?string $visioStatus = null,
    ): array {
        $isLate = $this->isLateJoin($heureDebut, $joinedAt);
        $leftEarly = $this->isEarlyLeave($heureFin, $leftAt);

        // Percentage is the primary driver of the label
        $effectivePercentage = $percentage ?? 0;

        if ($effectivePercentage === 100) {
            return [
                'label' => 'Présent (complet)',
                'icon' => '✅',
                'is_late' => false,
                'left_early' => false,
            ];
        }

        if ($effectivePercentage >= 90) {
            return [
                'label' => $visioStatus === 'terminee' ? "Présent ({$effectivePercentage}%)" : 'Présent',
                'icon' => '✅',
                'is_late' => $isLate,
                'left_early' => $leftEarly,
            ];
        }

        if ($effectivePercentage >= 50) {
            return [
                'label' => $this->buildPartialLabel($effectivePercentage, $isLate, $leftEarly, $visioStatus),
                'icon' => '⚠️',
                'is_late' => $isLate,
                'left_early' => $leftEarly,
            ];
        }

        return [
            'label' => $visioStatus === 'terminee' ? "Présent ({$effectivePercentage}%)" : 'Présent',
            'icon' => '⚠️',
            'is_late' => $isLate,
            'left_early' => $leftEarly,
        ];
    }

    /**
     * Returns true if `$joinedAt` is more than RETARD_SEUIL_MINUTES after `$heureDebut`.
     * Returns false when either timestamp is missing or unparseable.
     */
    private function isLateJoin(?string $heureDebut, ?string $joinedAt): bool
    {
        if (!$heureDebut || !$joinedAt) {
            return false;
        }

        try {
            $debut = new DateTimeImmutable($heureDebut);
            $joined = new DateTimeImmutable($joinedAt);
            $diffMinutes = ($joined->getTimestamp() - $debut->getTimestamp()) / 60;

            return $diffMinutes > self::RETARD_SEUIL_MINUTES;
        } catch (\Exception $e) {
            // Unparseable timestamps → not late (defensive)
            return false;
        }
    }

    /**
     * Returns true if `$leftAt` is more than RETARD_SEUIL_MINUTES before `$heureFin`.
     * Returns false when either timestamp is missing or unparseable.
     */
    private function isEarlyLeave(?string $heureFin, ?string $leftAt): bool
    {
        if (!$heureFin || !$leftAt) {
            return false;
        }

        try {
            $fin = new DateTimeImmutable($heureFin);
            $left = new DateTimeImmutable($leftAt);
            $diffMinutes = ($fin->getTimestamp() - $left->getTimestamp()) / 60;

            return $diffMinutes > self::RETARD_SEUIL_MINUTES;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Build the label for a 50-89% percentage range, combining late/leave info
     * + whether the visio is terminated (affects label format).
     */
    private function buildPartialLabel(
        int|float $percentage,
        bool $isLate,
        bool $leftEarly,
        ?string $visioStatus,
    ): string {
        $terminee = $visioStatus === 'terminee';

        if ($isLate && $leftEarly) {
            return $terminee ? "Partiel ({$percentage}%)" : 'Partiel';
        }

        if ($isLate) {
            return $terminee ? "Retard ({$percentage}%)" : 'Retard';
        }

        if ($leftEarly) {
            return $terminee ? "Départ anticipé ({$percentage}%)" : 'Départ anticipé';
        }

        return $terminee ? "Présent ({$percentage}%)" : 'Présent';
    }
}
