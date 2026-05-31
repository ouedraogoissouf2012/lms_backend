<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MatiereSeancesFetcher — fetches and enriches séances for a matière.
 *
 * Extracted from {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::matiereDetails}
 * (legacy lines 132-233 + 396-439).
 *
 * Responsibility:
 *   - Resolves the séances list according to the user role
 *     (teacher-dashboard / student-dashboard / coordinateur payload).
 *   - For students, filters out archived/hidden séances using local LMS state.
 *   - Enriches each séance with visio + classe effectif data.
 *
 * @see PRODUCTION_STANDARDS.md §1.1
 */
final class MatiereSeancesFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Resolve séances according to user role, filter students, then enrich.
     *
     * @param  array<string, mixed>  $matiereData  Original KLASSCI matiere payload.
     * @return array{seances: array<int, array<string, mixed>>, seances_enrichies: array<int, array<string, mixed>>}
     */
    public function fetchSeancesForUser(
        User $user,
        int $matiereId,
        string $klassciToken,
        array $matiereData,
    ): array {
        $seances = $this->fetchRawSeances($user, $matiereId, $klassciToken, $matiereData);

        if ($user->isStudent()) {
            $seances = $this->filterHiddenAndArchivedForStudent($seances, $user);
        }

        $seancesEnrichies = $this->enrichSeances($seances, $klassciToken);

        return [
            'seances' => $seances,
            'seances_enrichies' => $seancesEnrichies,
        ];
    }

    /**
     * @param  array<string, mixed>  $matiereData
     * @return array<int, array<string, mixed>>
     */
    private function fetchRawSeances(
        User $user,
        int $matiereId,
        string $klassciToken,
        array $matiereData,
    ): array {
        try {
            if ($user->isTeacher()) {
                return $this->fetchSeancesFromDashboard(
                    $klassciToken,
                    $matiereId,
                    'me/teacher-dashboard'
                );
            }

            if ($user->isStudent()) {
                $seances = $this->fetchSeancesFromDashboard(
                    $klassciToken,
                    $matiereId,
                    'me/dashboard'
                );

                if ($seances !== []) {
                    $this->logger->info('[DEBUG DATES] Séances récupérées de Klassci pour étudiant', [
                        'matiere_id' => $matiereId,
                        'user_id' => $user->id,
                        'count' => count($seances),
                        'premiere_seance' => $seances[0] ?? null,
                        'toutes_seances' => $seances,
                    ]);
                }

                return $seances;
            }

            // Coordinator: use seances_programmees from base matiere payload.
            /** @var array<int, array<string, mixed>> $seances */
            $seances = is_array($matiereData['seances_programmees'] ?? null)
                ? $matiereData['seances_programmees']
                : [];

            return $seances;
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération séances', [
                'matiere_id' => $matiereId,
                'user_role' => $user->role,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSeancesFromDashboard(string $klassciToken, int $matiereId, string $dashboardEndpoint): array
    {
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            $dashboardEndpoint,
            'GET'
        );

        /** @var array<int, array<string, mixed>> $matieresDashboard */
        $matieresDashboard = $dashboard['data']['matieres'] ?? [];
        $matiereInDashboard = collect($matieresDashboard)->firstWhere('id', $matiereId);

        if ($matiereInDashboard === null) {
            return [];
        }

        $matiereDetails = $this->klassciService->requestWithUserToken(
            $klassciToken,
            "matieres/{$matiereId}",
            'GET'
        );

        /** @var array<int, array<string, mixed>> $seances */
        $seances = $matiereDetails['data']['seances_programmees'] ?? [];

        return $seances;
    }

    /**
     * @param  array<int, array<string, mixed>>  $seances
     * @return array<int, array<string, mixed>>
     */
    private function filterHiddenAndArchivedForStudent(array $seances, User $user): array
    {
        $filtered = collect($seances)->filter(function (array $seance) use ($user): bool {
            $seanceId = $seance['id'] ?? null;

            if ($seanceId === null) {
                return true; // Keep seances without ID.
            }

            $localSeance = Seance::where('klassci_seance_id', $seanceId)
                ->orWhere('id', $seanceId)
                ->first();

            if ($localSeance === null) {
                return true; // Pure KLASSCI séance, keep.
            }

            if (!$localSeance->is_active) {
                return false;
            }

            if (SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
                return false;
            }

            return true;
        })->values()->toArray();

        $this->logger->info('Séances filtrées pour étudiant', [
            'user_id' => $user->id,
            'count_after_filter' => count($filtered),
        ]);

        return $filtered;
    }

    /**
     * @param  array<int, array<string, mixed>>  $seances
     * @return array<int, array<string, mixed>>
     */
    private function enrichSeances(array $seances, string $klassciToken): array
    {
        return collect($seances)->map(function (array $seance) use ($klassciToken): array {
            $visioData = isset($seance['id'])
                ? Seance::where('klassci_seance_id', $seance['id'])->first()
                : null;

            $seanceEnrichie = $seance;

            // Effectif classe.
            if (isset($seance['classe']['id'])) {
                try {
                    $classeDetails = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "classes/{$seance['classe']['id']}",
                        'GET'
                    );
                    $seanceEnrichie['classe_effectif'] = $classeDetails['data']['classe']['places_occupees'] ?? 0;
                } catch (Throwable) {
                    $seanceEnrichie['classe_effectif'] = 0;
                }
            } else {
                $seanceEnrichie['classe_effectif'] = 0;
            }

            if ($visioData !== null) {
                $seanceEnrichie['visio_enabled'] = $visioData->visio_enabled;
                $seanceEnrichie['visio_type'] = $visioData->visio_type;
                $seanceEnrichie['visio_status'] = $visioData->visio_status;
                $seanceEnrichie['visio_active'] = $visioData->visio_active;
                $seanceEnrichie['visio_room_id'] = $visioData->visio_room_id;
                $seanceEnrichie['visio_participants_count'] = $visioData->current_participants_count ?? 0;
            } else {
                $seanceEnrichie['visio_enabled'] = false;
                $seanceEnrichie['visio_type'] = null;
                $seanceEnrichie['visio_status'] = null;
                $seanceEnrichie['visio_active'] = false;
                $seanceEnrichie['visio_room_id'] = null;
                $seanceEnrichie['visio_participants_count'] = 0;
            }

            return $seanceEnrichie;
        })->all();
    }
}
