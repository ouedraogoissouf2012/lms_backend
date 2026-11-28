<?php

declare(strict_types=1);

namespace App\Services\Attendances;

use App\Models\ESBTPAttendance;
use Illuminate\Support\Collection;

/**
 * Les statistiques des présences d'UNE séance (`GET /lms/seances/{id}/attendances`) : participants, durée moyenne,
 * taux de présence. Extraites de {@see SeanceAttendancesQueryService} (§1.1, #1649).
 *
 * #1649 : la marque de l'appel l'emporte ({@see PresenceRetenue}). Sans marque, le critère visio PROPRE à cet écran
 * (plus de 3 minutes de connexion) s'applique, comme avant ; le rapport, lui, retient le statut visio. Ces deux
 * critères visio préexistaient : le calcul unique du taux est l'objet de #1683.
 */
final class StatistiquesDesPresences
{
    /**
     * @param  Collection<int, ESBTPAttendance>  $allAttendances
     * @return array{total_participants:int, average_duration:int, presence_rate:int}
     */
    public function de(Collection $allAttendances): array
    {
        // Statistiques (non-observateurs uniquement)
        $nonObserverAttendances = $allAttendances->filter(fn (ESBTPAttendance $att): bool => ! $att->is_observer);
        $totalParticipants = $nonObserverAttendances->count();

        $moyenne = $nonObserverAttendances->pluck('duration_minutes')
            ->filter(fn ($d) => $d !== null && $d > 0)
            ->avg();
        // `avg()` rend null sans durée : la moyenne est alors 0.
        $averageDuration = $moyenne !== null ? (int) round($moyenne) : 0;

        $validPresences = $nonObserverAttendances->filter(
            fn (ESBTPAttendance $att): bool => $att->mark !== null
                ? PresenceRetenue::estPresente($att)
                : $att->duration_minutes !== null && $att->duration_minutes > 3,
        )->count();

        return [
            'total_participants' => $totalParticipants,
            'average_duration' => $averageDuration,
            'presence_rate' => $totalParticipants > 0 ? (int) round(($validPresences / $totalParticipants) * 100) : 0,
        ];
    }
}
