<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
use App\Services\Seances\LocalSeanceLookup;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MatiereSeancesFetcher — fetches and enriches séances for a matière.
 *
 * Extracted from {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::matiereDetails}
 * (legacy lines 132-233 + 396-439). Refactored for issue #517 (N+1 HTTP+SQL) :
 * reuses {@see LocalSeanceLookup} (#476) for a single mutualized local
 * preload (filter + visio) instead of a `Seance::where(...)->first()` per
 * séance, and batches the classe-effectif lookups via
 * `fetchManyClassesDetails` instead of 1 sequential HTTP call per séance.
 *
 * Responsibility:
 *   - Resolves the séances list according to the user role
 *     (teacher-dashboard / student-dashboard / coordinateur payload).
 *   - For students, filters out archived/hidden séances using local LMS state.
 *   - Enriches each séance with visio + classe effectif data.
 *
 * @see PRODUCTION_STANDARDS.md §1.1, §1.4
 */
final class MatiereSeancesFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
        private readonly LocalSeanceLookup $localLookup,
    ) {}

    /**
     * Resolve séances according to user role, preload local state ONCE
     * (mutualisé filtre + visio — #476/#517), filter students, then enrich.
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

        $this->localLookup->preload(
            $this->collectKlassciSeanceIds($seances),
            $user->isStudent() ? $user : null,
        );

        if ($user->isStudent()) {
            $seances = $this->filterHiddenAndArchivedForStudent($seances);
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
     * @return list<int>
     */
    private function collectKlassciSeanceIds(array $seances): array
    {
        return KlassciPayload::uniqueIntIds($seances, fn (array $seance): ?int => KlassciPayload::toInt($seance['id'] ?? null));
    }

    /**
     * Filtre les séances archivées/masquées pour l'étudiant, résolues EN
     * MÉMOIRE depuis le préchargement `LocalSeanceLookup` (klassci_seance_id
     * uniquement — la colonne est NOT NULL et unique par institution depuis
     * la migration `2026_07_20_000001_fix_seances_unique_per_institution`,
     * donc toujours l'identifiant local autoritaire). L'ancien fallback
     * `orWhere('id', $seanceId)` — qui matchait aussi sur le PK local — n'est
     * pas reproduit ici : c'est le même choix déjà fait pour
     * `UpcomingSeancesFetcher` (#476), `LocalSeanceLookup` ne l'a jamais eu.
     *
     * @param  array<int, array<string, mixed>>  $seances
     * @return array<int, array<string, mixed>>
     */
    private function filterHiddenAndArchivedForStudent(array $seances): array
    {
        $filtered = collect($seances)->filter(function (array $seance): bool {
            $seanceId = KlassciPayload::toInt($seance['id'] ?? null);

            return ! $this->localLookup->isArchived($seanceId) && ! $this->localLookup->isHidden($seanceId);
        })->values()->toArray();

        $this->logger->info('Séances filtrées pour étudiant', [
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
        $classesDetails = $this->fetchClassesDetails($seances, $klassciToken);

        return collect($seances)->map(function (array $seance) use ($classesDetails): array {
            $seanceEnrichie = $seance;
            $seanceEnrichie['classe_effectif'] = KlassciPayload::classeEffectif($seance, $classesDetails);

            $visioData = $this->localLookup->seanceFor(KlassciPayload::toInt($seance['id'] ?? null));

            return $this->withVisioFields($seanceEnrichie, $visioData);
        })->all();
    }

    /**
     * Batch (#517) : 1 seul `fetchManyClassesDetails` pour TOUTES les classes
     * distinctes des séances, au lieu d'un `classes/{id}` séquentiel par séance.
     * Un échec du pool à l'échelle de l'appel (config/connectivité KLASSCI,
     * pas un échec par id — déjà toléré par `KlassciBatchFetcher`) dégrade
     * gracieusement vers `classe_effectif = 0` pour toutes les séances,
     * plutôt que de faire échouer toute la réponse (parité avec l'ancien
     * `try/catch` par séance).
     *
     * @param  array<int, array<string, mixed>>  $seances
     * @return array<int, array<string, mixed>>
     */
    private function fetchClassesDetails(array $seances, string $klassciToken): array
    {
        $classeIds = KlassciPayload::uniqueIntIds($seances, KlassciPayload::classeIdFor(...));
        if ($classeIds === []) {
            return [];
        }

        try {
            return $this->klassciService->fetchManyClassesDetails($classeIds, $klassciToken);
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération effectifs de classe (batch)', [
                'classe_ids' => $classeIds,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  array<string, mixed>  $seanceEnrichie
     * @return array<string, mixed>
     */
    private function withVisioFields(array $seanceEnrichie, ?Seance $visioData): array
    {
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
    }
}
