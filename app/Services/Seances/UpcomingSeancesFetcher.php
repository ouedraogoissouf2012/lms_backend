<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\User;
use App\Services\KlassciProxyService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * UpcomingSeancesFetcher — KLASSCI walker for the "upcoming séances" listing.
 *
 * Extracted from `LMSSeancesQueryController::upcomingSeances` (~196 lines)
 * during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Builds the upcoming-séances listing for any role:
 *   1. Walk all matières (`/matieres`) for the user.
 *   2. For each matière, walk `seances_programmees`.
 *   3. Filter by date window, optional classe id, hidden flag (students only).
 *   4. Map to the legacy output shape (programmation + matiere + classe + visio).
 *
 * @see \App\Services\SeancesListQueryService (orchestrator)
 */
final class UpcomingSeancesFetcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
        private readonly ManagerSeancesLocalFetcher $managerFetcher,
        private readonly LocalSeanceLookup $localLookup,
        private readonly UpcomingSeanceMapper $mapper,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function fetch(User $user, string $klassciToken, string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
    {
        if ($user->isManager()) {
            return $this->fetchForManager($dateDebut, $dateFin, $teacherId, $classeId);
        }

        // WORKAROUND: endpoint emploi-temps bugué, on utilise matieres/{id}
        // qui retourne seances_programmees (fonctionne!)
        $this->logger->info('Récupération séances via endpoint /matieres (workaround)');

        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);

        try {
            [$matieres, $matieresDetails] = $this->loadMatieresWithDetails($klassciToken);
            $candidates = $this->collectCandidates($matieres, $matieresDetails, $dateDebut, $dateFin, $classeId);
            $seances = $this->assembleVisibleSeances($candidates, $user);

            $this->logger->info('Séances récupérées via matieres', ['count' => $seances->count()]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur récupération séances via matieres', [
                'error' => $e->getMessage(),
            ]);
        }

        return $this->enrichWithVisio($seances);
    }

    /**
     * Chemin manager : liste servie directement depuis le cache local.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchForManager(string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
    {
        $localSeances = $this->managerFetcher->fetch($dateDebut, $dateFin, $teacherId, $classeId);

        $this->logger->info('Séances à venir servies depuis la BDD locale', [
            'count' => $localSeances->count(),
            'teacher_id' => $teacherId,
            'classe_id' => $classeId,
        ]);

        return $localSeances;
    }

    /**
     * Récupère les matières de l'utilisateur puis, en UN pool batch (#135),
     * leurs détails (séances programmées). ID échoué = absent du map.
     *
     * @return array{0: Collection<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function loadMatieresWithDetails(string $klassciToken): array
    {
        $matieresResponse = $this->klassciService->requestWithUserToken($klassciToken, 'matieres', 'GET');
        $matieres = collect(KlassciPayload::listOfArrays($matieresResponse['data'] ?? null));

        $matiereIds = [];
        foreach ($matieres as $matiere) {
            $id = KlassciPayload::toInt(KlassciPayload::asArray($matiere)['id'] ?? null);
            if ($id !== null) {
                $matiereIds[] = $id;
            }
        }

        $details = $this->klassciService->fetchManyMatieresDetails(array_values(array_unique($matiereIds)), $klassciToken);

        return [$matieres, $details];
    }

    /**
     * Phase 2 (#476) : pré-charge l'état local en UN whereIn mutualisé (filtre +
     * visio), puis assemble les séances visibles mappées.
     *
     * @param  list<array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}>  $candidates
     * @return Collection<int, array<string, mixed>>
     */
    private function assembleVisibleSeances(array $candidates, User $user): Collection
    {
        // Un seul pré-chargement : alimente le filtre archivé/masqué (étudiants) ET
        // l'enrichissement visio. `null` en non-étudiant évite la requête hidden.
        $this->localLookup->preload(
            $this->collectKlassciSeanceIds($candidates),
            $user->isStudent() ? $user : null,
        );

        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);
        foreach ($candidates as [$seancesProgrammees, $matiereArr]) {
            $seancesVisibles = $this->rejectHiddenOrArchived($seancesProgrammees, $user);
            $seances = $seances->concat($this->mapper->map($seancesVisibles, $matiereArr));
        }

        return $seances;
    }

    /**
     * Phase 1 (#476) — collecte les couples (séances filtrées date/classe, matière)
     * SANS aucune requête locale. Le filtre archivé/masqué est reporté en phase 2
     * (après pré-chargement), pour éliminer les N+1.
     *
     * @param  Collection<int, array<string, mixed>>  $matieres
     * @param  array<int, array<string, mixed>>  $matieresDetails
     * @return list<array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}>
     */
    private function collectCandidates(Collection $matieres, array $matieresDetails, string $dateDebut, string $dateFin, ?int $classeId): array
    {
        $candidates = [];
        foreach ($matieres as $matiere) {
            $matiereArr = KlassciPayload::asArray($matiere);
            $matiereId = KlassciPayload::toInt($matiereArr['id'] ?? null);
            if ($matiereId === null || ! isset($matieresDetails[$matiereId])) {
                continue;
            }

            $seancesProgrammees = collect(
                KlassciPayload::listOfArrays(KlassciPayload::asArray($matieresDetails[$matiereId]['data'] ?? null)['seances_programmees'] ?? null)
            );
            $candidates[] = [$this->filterByDateAndClasse($seancesProgrammees, $dateDebut, $dateFin, $classeId), $matiereArr];
        }

        return $candidates;
    }

    /**
     * Filtre par fenêtre de dates + classe optionnelle. Aucune requête locale.
     *
     * @param  Collection<int, array<string, mixed>>  $seances
     * @return Collection<int, array<string, mixed>>
     */
    private function filterByDateAndClasse(Collection $seances, string $dateDebut, string $dateFin, ?int $classeId): Collection
    {
        $filtered = $seances->filter(function (array $seance) use ($dateDebut, $dateFin) {
            $dateSeance = KlassciPayload::toStringOrNull(
                KlassciPayload::asArray($seance['programmation'] ?? null)['date'] ?? null
            );

            return $dateSeance !== null && $dateSeance >= $dateDebut && $dateSeance <= $dateFin;
        });

        if ($classeId) {
            $filtered = $filtered->filter(function (array $seance) use ($classeId) {
                return KlassciPayload::toInt(KlassciPayload::asArray($seance['classe'] ?? null)['id'] ?? null) === $classeId;
            });
        }

        return $filtered;
    }

    /**
     * Tous les klassci_seance_id non nuls des candidats, dédupliqués.
     *
     * @param  list<array{0: Collection<int, array<string, mixed>>, 1: array<string, mixed>}>  $candidates
     * @return list<int>
     */
    private function collectKlassciSeanceIds(array $candidates): array
    {
        $ids = [];
        foreach ($candidates as [$seances]) {
            foreach ($seances as $seance) {
                $id = KlassciPayload::toInt($seance['id'] ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Phase 2 (#476) — pour les étudiants, écarte les séances archivées ou masquées,
     * résolues EN MÉMOIRE depuis le pré-chargement (plus aucune requête par séance).
     * Enseignants/coordinateurs/admins voient tout (comportement inchangé).
     *
     * @param  Collection<int, array<string, mixed>>  $seances
     * @return Collection<int, array<string, mixed>>
     */
    private function rejectHiddenOrArchived(Collection $seances, User $user): Collection
    {
        if (! $user->isStudent()) {
            return $seances;
        }

        return $seances->filter(function (array $seance): bool {
            $kid = KlassciPayload::toInt($seance['id'] ?? null);

            return ! $this->localLookup->isArchived($kid) && ! $this->localLookup->isHidden($kid);
        });
    }

    /**
     * Enrichir avec les infos visio du LMS.
     *
     * @param Collection<int, array<string, mixed>> $seances
     * @return Collection<int, array<string, mixed>>
     */
    private function enrichWithVisio(Collection $seances): Collection
    {
        /** @var Collection<int, array<string, mixed>> $enriched */
        $enriched = $seances->map(function (array $seance): array {
            $seance = $this->withDureeMinutes($seance);

            // Chercher infos visio dans la table locale — résolu EN MÉMOIRE depuis
            // le même pré-chargement que le filtre (mutualisation #476, plus de N+1).
            $visioInfo = $this->localLookup->seanceFor(KlassciPayload::toInt($seance['id'] ?? null));

            if ($visioInfo) {
                $seance['visio_enabled'] = $visioInfo->visio_enabled;
                $seance['visio_type'] = $visioInfo->visio_type;
                $seance['visio_room_id'] = $visioInfo->visio_room_id;
                $seance['visio_active'] = $visioInfo->visio_active;
                $seance['visio_started_at'] = $visioInfo->visio_started_at?->toISOString();
                $seance['visio_ended_at'] = $visioInfo->visio_ended_at?->toISOString();
            } else {
                // Pas de visio configurée pour cette séance
                $seance['visio_enabled'] = false;
                $seance['visio_type'] = null;
                $seance['visio_room_id'] = null;
                $seance['visio_active'] = false;
                $seance['visio_started_at'] = null;
                $seance['visio_ended_at'] = null;
            }

            return $seance;
        });

        return $enriched;
    }

    /**
     * Ajoute `duree_minutes` (entier, minutes) à partir des heures de
     * `programmation`, qui sont des datetimes ISO déjà alignés sur la date de
     * séance (UpcomingSeanceMapper + SeanceProgrammationNormalizer). On les parse
     * tels quels, SANS reconcaténer la date — cohérent avec
     * SeanceDetailQueryService (#487). Heure(s) manquante(s) → clé omise.
     *
     * @param  array<string, mixed>  $seance
     * @return array<string, mixed>
     */
    private function withDureeMinutes(array $seance): array
    {
        $prog = KlassciPayload::asArray($seance['programmation'] ?? null);
        $heureDebutRaw = KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null);
        $heureFinRaw = KlassciPayload::toStringOrNull($prog['heure_fin'] ?? null);

        if ($heureDebutRaw !== null && $heureFinRaw !== null) {
            $heureDebut = Carbon::parse($heureDebutRaw);
            $heureFin = Carbon::parse($heureFinRaw);
            $seance['duree_minutes'] = (int) $heureDebut->diffInMinutes($heureFin, absolute: true);
        }

        return $seance;
    }
}
