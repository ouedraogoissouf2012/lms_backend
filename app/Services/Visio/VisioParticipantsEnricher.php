<?php

declare(strict_types=1);

namespace App\Services\Visio;

use App\Models\ESBTPAttendance;
use App\Services\AttendanceStatusService;

/**
 * VisioParticipantsEnricher — extrait verbatim de
 * `LMSVisioParticipantController::getVisioParticipants()` (boucle de
 * fusion `allClassStudents` × `actualParticipantsById`).
 *
 * Pour chaque étudiant de la classe : décide s'il est PRÉSENT
 * (entrée `esbtp_attendance` correspondante) ou ABSENT, puis enrichit
 * sa fiche avec durée, pourcentage, statut/icône, indicateurs de
 * retard/départ anticipé.
 *
 * Co-extraction avec `VisioParticipantsListService` afin de garder
 * chacun ≤300 lignes (§1.1).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 */
final class VisioParticipantsEnricher
{
    public function __construct(
        private readonly AttendanceStatusService $attendanceStatus,
    ) {}

    /**
     * Construit la fiche enrichie d'un étudiant (présent ou absent).
     *
     * @param array<string, mixed>             $student
     * @param array<int, ESBTPAttendance>      $actualParticipantsById
     *
     * @return array<string, mixed>
     */
    public function enrich(
        array $student,
        array $actualParticipantsById,
        int|float $seanceDurationMinutes,
        ?string $heureDebut,
        ?string $heureFin,
        ?string $visioStatus,
    ): array {
        $studentId = $student['id'] ?? null;
        $isPresent = $studentId !== null && isset($actualParticipantsById[$studentId]);

        $studentData = [
            'user_id' => $studentId,
            'nom' => $student['nom'] ?? '',
            'prenom' => $student['prenom'] ?? '',
            'email' => $student['email'] ?? '',
        ];

        if (! $isPresent) {
            return array_merge($studentData, $this->absentPayload());
        }

        $attendance = $actualParticipantsById[$studentId];

        return array_merge($studentData, $this->presentPayload(
            $attendance,
            $seanceDurationMinutes,
            $heureDebut,
            $heureFin,
            $visioStatus,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPayload(
        ESBTPAttendance $attendance,
        int|float $seanceDurationMinutes,
        ?string $heureDebut,
        ?string $heureFin,
        ?string $visioStatus,
    ): array {
        // Calculer la durée dynamiquement si l'étudiant est toujours connecté
        if ($attendance->status === 'connected' && $attendance->joined_at) {
            // Si toujours connecté, calculer la durée depuis joined_at jusqu'à maintenant
            $durationMinutes = $attendance->joined_at->diffInMinutes(now());
        } else {
            // Si déconnecté, utiliser la durée enregistrée
            $durationMinutes = $attendance->duration_minutes ?? 0;
        }

        $percentage = $seanceDurationMinutes > 0
            ? round(($durationMinutes / $seanceDurationMinutes) * 100)
            : 0;

        // Déterminer le statut
        $status = $this->attendanceStatus->determine(
            $percentage,
            $attendance->joined_at?->toIso8601String(),
            $attendance->left_at?->toIso8601String(),
            $heureDebut,
            $heureFin,
            $visioStatus,
        );

        // Formatter la durée dynamiquement
        $hours = (int) floor($durationMinutes / 60);
        $minutes = (int) $durationMinutes % 60;
        $durationFormatted = $hours > 0 ? "{$hours}h {$minutes}min" : "{$minutes}min";

        // Déterminer left_at dynamiquement si toujours connecté
        $leftAtFull = $attendance->left_at?->format('Y-m-d H:i:s');
        $leftAtDisplay = $attendance->left_at?->format('H:i');

        if ($attendance->status === 'connected') {
            $leftAtDisplay = 'En cours';
            $leftAtFull = null; // NULL = toujours connecté
        }

        return [
            'status' => $status['label'],
            'status_icon' => $status['icon'],
            'percentage' => $percentage,
            'duration_minutes' => $durationMinutes,
            'duration_formatted' => $durationFormatted,
            'joined_at' => $attendance->joined_at?->format('H:i'),
            'left_at' => $leftAtDisplay,
            'joined_at_full' => $attendance->joined_at?->format('Y-m-d H:i:s'),
            'left_at_full' => $leftAtFull,
            'is_late' => $status['is_late'],
            'left_early' => $status['left_early'],
            'is_present' => true,
            'is_connected' => $attendance->status === 'connected', // Nouveau champ
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function absentPayload(): array
    {
        return [
            'status' => 'Absent',
            'status_icon' => '❌',
            'percentage' => 0,
            'duration_minutes' => 0,
            'duration_formatted' => '-',
            'joined_at' => null,
            'left_at' => null,
            'joined_at_full' => null,
            'left_at_full' => null,
            'is_late' => false,
            'left_early' => false,
            'is_present' => false,
        ];
    }
}
