<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ESBTPAttendance;
use App\Models\Seance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * SeancesHistoryQueryService — paginated history of past séances with stats.
 *
 * Extracted from `LMSSeancesQueryController::getSeancesHistory` (~140 lines)
 * during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Returns the paginated list of séances that had a visio started
 * (`visio_started_at IS NOT NULL`), enriched with attendance statistics
 * (participants_count, presence rate, average duration, séance duration).
 *
 * Role scoping:
 *   - Teacher → scoped to their `klassci_enseignant_id`.
 *   - Coordinator / superAdmin → no scope (see all).
 *
 * Multi-tenant scoping is automatic via `Seance::BelongsToInstitution`.
 *
 * @see \App\Http\Controllers\API\LMS\LMSSeancesHistoryController (route handler)
 */
final class SeancesHistoryQueryService
{
    private const PRESENCE_DURATION_THRESHOLD_MINUTES = 3;
    private const MAX_SEANCE_DURATION_MINUTES = 1440;

    /**
     * Returns the paginated enriched séances history.
     *
     * @param array{date_from?: string|null, date_to?: string|null, search?: string|null, per_page?: int|null} $filters
     *
     * @return array{
     *     data: array<int, array<string, mixed>>,
     *     pagination: array{current_page: int, per_page: int, total: int, last_page: int}
     * }
     */
    public function getHistoryForUser(User $user, array $filters): array
    {
        $query = $this->baseQuery($user);
        $this->applyOptionalFilters($query, $filters);

        $perPage = $filters['per_page'] ?? 50;
        $seances = $query->paginate($perPage);

        return [
            'data' => $this->enrichWithStats($seances->getCollection())->values()->all(),
            'pagination' => [
                'current_page' => $seances->currentPage(),
                'per_page' => $seances->perPage(),
                'total' => $seances->total(),
                'last_page' => $seances->lastPage(),
            ],
        ];
    }

    /**
     * Base query : seulement les séances qui ont eu une visio démarrée,
     * scoped au rôle de l'utilisateur (teacher uniquement = ses propres séances).
     *
     * @return Builder<Seance>
     */
    private function baseQuery(User $user): Builder
    {
        $query = Seance::whereNotNull('visio_started_at')
            ->orderBy('visio_started_at', 'desc');

        // Filtre par rôle
        if ($user->isTeacher()) {
            $query->where('klassci_enseignant_id', $user->klassci_id);
        }
        // coordinateur / superAdmin : pas de filtre, voit tout

        return $query;
    }

    /**
     * @param Builder<Seance> $query
     * @param array{date_from?: string|null, date_to?: string|null, search?: string|null, per_page?: int|null} $filters
     */
    private function applyOptionalFilters(Builder $query, array $filters): void
    {
        $dateFrom = $filters['date_from'] ?? null;
        $dateTo = $filters['date_to'] ?? null;
        $search = $filters['search'] ?? null;

        if ($dateFrom) {
            $query->where('visio_started_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->where('visio_started_at', '<=', $dateTo . ' 23:59:59');
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('matiere_nom', 'like', "%{$search}%")
                  ->orWhere('classe_nom', 'like', "%{$search}%")
                  ->orWhere('enseignant_nom', 'like', "%{$search}%")
                  ->orWhere('titre', 'like', "%{$search}%");
            });
        }
    }

    /**
     * Enrichir chaque séance avec les statistiques de présence.
     *
     * @param Collection<int, Seance> $seances
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichWithStats(Collection $seances): Collection
    {
        return $seances->map(function (Seance $seance): array {
            // Récupérer les attendances non-observateurs
            $attendances = ESBTPAttendance::where('seance_id', $seance->id)
                ->where(function ($q) {
                    $q->where('is_observer', false)
                      ->orWhereNull('is_observer');
                })
                ->get();

            $participantsCount = $attendances->count();

            // Durées valides (> 0)
            $durations = $attendances->pluck('duration_minutes')
                ->filter(fn($d) => $d !== null && $d > 0);

            $avgDuration = $durations->count() > 0
                ? round($durations->avg())
                : 0;

            // Présence valide = duration > 3 minutes
            $validPresences = $durations->filter(fn($d) => $d > self::PRESENCE_DURATION_THRESHOLD_MINUTES)->count();

            $presenceRate = $participantsCount > 0
                ? round(($validPresences / $participantsCount) * 100)
                : 0;

            $seanceDurationMinutes = $this->computeSeanceDurationMinutes($seance);

            return [
                'id' => $seance->id,
                'klassci_seance_id' => $seance->klassci_seance_id,
                'titre' => $seance->titre ?? 'Séance #' . $seance->klassci_seance_id,
                'matiere' => [
                    'nom' => $seance->matiere_nom ?? 'Matière inconnue',
                ],
                'classe' => [
                    'nom' => $seance->classe_nom ?? '-',
                ],
                'date' => $seance->date_seance
                    ? $seance->date_seance->format('Y-m-d')
                    : ($seance->visio_started_at
                        ? $seance->visio_started_at->format('Y-m-d')
                        : null),
                'visio_started_at' => $seance->visio_started_at?->toIso8601String(),
                'visio_ended_at' => $seance->visio_ended_at?->toIso8601String(),
                'duree_seance_minutes' => $seanceDurationMinutes,
                'participants_count' => $participantsCount,
                'duree_moyenne_minutes' => $avgDuration,
                'taux_presence' => $presenceRate,
                'enseignant_nom' => $seance->enseignant_nom,
            ];
        });
    }

    /**
     * Compute the séance duration in minutes, capped to a 24h sanity limit
     * to avoid garbage data from un-closed visio sessions.
     */
    private function computeSeanceDurationMinutes(Seance $seance): ?int
    {
        if (!$seance->visio_started_at || !$seance->visio_ended_at) {
            return null;
        }

        $diffMinutes = $seance->visio_started_at->diffInMinutes($seance->visio_ended_at);
        if ($diffMinutes > self::MAX_SEANCE_DURATION_MINUTES) {
            return null;
        }

        return (int) $diffMinutes;
    }
}
