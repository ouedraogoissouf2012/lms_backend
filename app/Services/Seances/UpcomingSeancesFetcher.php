<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\SeanceUserHidden;
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
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function fetch(User $user, string $klassciToken, string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
    {
        if ($user->isManager()) {
            $localSeances = $this->fetchFromLocalStore($dateDebut, $dateFin, $teacherId, $classeId);

            $this->logger->info('Séances à venir servies depuis la BDD locale', [
                'count' => $localSeances->count(),
                'teacher_id' => $teacherId,
                'classe_id' => $classeId,
            ]);

            return $localSeances;
        }

        // WORKAROUND: endpoint emploi-temps bugué, on utilise matieres/{id}
        // qui retourne seances_programmees (fonctionne!)
        $this->logger->info('Récupération séances via endpoint /matieres (workaround)');

        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);

        try {
            $matieresResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'matieres',
                'GET'
            );

            $matieres = collect($matieresResponse['data'] ?? []);

            // PERF (#135) : paralléliser les détails matières — était N appels
            // `matieres/{id}` séquentiels. ID échoué = absent du map (log émis par
            // le batch fetcher) → matière omise, comme l'ancien try/catch.
            $matiereIds = [];
            foreach ($matieres as $matiere) {
                $id = KlassciPayload::toInt(KlassciPayload::asArray($matiere)['id'] ?? null);
                if ($id !== null) {
                    $matiereIds[] = $id;
                }
            }
            $matiereIds = array_values(array_unique($matiereIds));
            $matieresDetails = $this->klassciService->fetchManyMatieresDetails($matiereIds, $klassciToken);

            foreach ($matieres as $matiere) {
                $matiereArr = KlassciPayload::asArray($matiere);
                $matiereId = KlassciPayload::toInt($matiereArr['id'] ?? null);
                if ($matiereId === null) {
                    continue;
                }

                $matiereDetails = $matieresDetails[$matiereId] ?? null;
                if ($matiereDetails === null) {
                    continue;
                }

                $seancesProgrammees = collect(
                    KlassciPayload::listOfArrays(KlassciPayload::asArray($matiereDetails['data'] ?? null)['seances_programmees'] ?? null)
                );
                $seancesFiltrees = $this->filterSeances($seancesProgrammees, $user, $dateDebut, $dateFin, $classeId);
                $seancesMapped = $this->mapSeances($seancesFiltrees, $matiereArr);
                $seances = $seances->concat($seancesMapped);
            }

            $this->logger->info('Séances récupérées via matieres', ['count' => $seances->count()]);

        } catch (\Exception $e) {
            $this->logger->error('Erreur récupération séances via matieres', [
                'error' => $e->getMessage()
            ]);
        }

        return $this->enrichWithVisio($seances);
    }

    /**
     * Managers (coordinateurs/admins) doivent pouvoir ouvrir l'écran sans
     * parcourir toutes les matières KLASSCI. La liste se base sur la sync locale
     * des séances et garde la forme attendue par les vues calendrier/visio.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchFromLocalStore(string $dateDebut, string $dateFin, ?int $teacherId, ?int $classeId): Collection
    {
        $start = Carbon::parse($dateDebut)->startOfDay();
        $end = Carbon::parse($dateFin)->endOfDay();

        $query = Seance::query()
            ->withConnectedParticipantsCount()
            ->where('is_active', true)
            ->whereNotNull('klassci_seance_id')
            ->whereNotNull('date_seance')
            ->whereBetween('date_seance', [$start, $end]);

        if ($teacherId !== null) {
            $query->where(function ($query) use ($teacherId): void {
                $query->where('klassci_enseignant_id', $teacherId)
                    ->orWhere('created_by', $teacherId);
            });
        }

        if ($classeId !== null) {
            $query->where('klassci_classe_id', $classeId);
        }

        return $query
            ->orderBy('date_seance')
            ->get()
            ->map(fn (Seance $seance): array => $this->mapLocalSeance($seance));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLocalSeance(Seance $seance): array
    {
        $start = $seance->date_seance ? Carbon::parse($seance->date_seance)->copy() : null;
        $end = $start?->copy()->addHours(2);
        $date = $start?->format('Y-m-d');
        $startIso = $start?->toISOString();
        $endIso = $end?->toISOString();
        $participantsCount = $seance->current_participants_count ?? 0;

        return [
            'id' => $seance->klassci_seance_id ?? $seance->id,
            'klassci_seance_id' => $seance->klassci_seance_id,
            'date_seance' => $date,
            'date_debut' => $startIso,
            'date_fin' => $endIso,
            'heure_debut' => $start?->format('H:i'),
            'heure_fin' => $end?->format('H:i'),
            'salle' => null,
            'programmation' => [
                'date' => $date,
                'heure_debut' => $startIso,
                'heure_fin' => $endIso,
                'salle' => null,
            ],
            'matiere' => [
                'id' => $seance->klassci_matiere_id,
                'libelle' => $seance->matiere_nom ?? 'N/A',
                'nom' => $seance->matiere_nom ?? 'N/A',
                'code' => null,
            ],
            'classe' => [
                'id' => $seance->klassci_classe_id,
                'libelle' => $seance->classe_nom ?? 'N/A',
                'nom' => $seance->classe_nom ?? 'N/A',
                'name' => $seance->classe_nom ?? 'N/A',
                'effectif' => $seance->classe_effectif ?? 0,
            ],
            'enseignant' => [
                'id' => $seance->klassci_enseignant_id,
                'nom' => $seance->enseignant_nom ?? 'Non assigné',
                'prenom' => '',
            ],
            'visio_enabled' => $seance->visio_enabled,
            'visio_type' => $seance->visio_type,
            'visio_room_id' => $seance->visio_room_id,
            'visio_active' => $seance->visio_active,
            'visio_status' => $seance->visio_status,
            'visio_started_at' => $seance->visio_started_at?->toISOString(),
            'visio_ended_at' => $seance->visio_ended_at?->toISOString(),
            'visio_participants_count' => $participantsCount,
            'visio' => [
                'enabled' => $seance->visio_enabled,
                'active' => $seance->visio_active,
                'status' => $seance->visio_status,
                'room_id' => $seance->visio_room_id,
                'started_at' => $seance->visio_started_at?->toISOString(),
                'ended_at' => $seance->visio_ended_at?->toISOString(),
                'participants_count' => $participantsCount,
            ],
            'statut' => $seance->visio_status ?? 'programme',
        ];
    }

    /**
     * @param Collection<int, array<string, mixed>> $seancesProgrammees
     * @return Collection<int, array<string, mixed>>
     */
    private function filterSeances(Collection $seancesProgrammees, User $user, string $dateDebut, string $dateFin, ?int $classeId): Collection
    {
        // Filtrer par date
        $filtered = $seancesProgrammees->filter(function ($seance) use ($dateDebut, $dateFin) {
            $dateSeance = $seance['programmation']['date'] ?? null;
            return $dateSeance && $dateSeance >= $dateDebut && $dateSeance <= $dateFin;
        });

        // Filtrer par classe si spécifié
        if ($classeId) {
            $filtered = $filtered->filter(function ($seance) use ($classeId) {
                return isset($seance['classe']['id']) && $seance['classe']['id'] == $classeId;
            });
        }

        // IMPORTANT: Pour les étudiants, filtrer les séances archivées et masquées
        // Enseignants/Coordinateurs/Admins voient tout
        if ($user->isStudent()) {
            $filtered = $filtered->filter(function ($seance) use ($user) {
                $localSeance = Seance::where('klassci_seance_id', $seance['id'])->first();

                // Si la séance existe en local mais est archivée, ne pas la montrer aux étudiants
                if ($localSeance && !$localSeance->is_active) {
                    return false;
                }

                // Si la séance est masquée par l'étudiant, ne pas la montrer
                if ($localSeance && SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
                    return false;
                }

                return true;
            });
        }

        return $filtered;
    }

    /**
     * @param Collection<int, array<string, mixed>> $seances
     * @param array<string, mixed> $matiere
     * @return Collection<int, array<string, mixed>>
     */
    private function mapSeances(Collection $seances, array $matiere): Collection
    {
        // Enrichir avec info matière et formater
        // IMPORTANT: Le frontend attend seance.programmation.date, pas seance.date_seance
        return $seances->map(function (array $seance) use ($matiere) {
            $prog = KlassciPayload::asArray($seance['programmation'] ?? null);
            $classe = KlassciPayload::asArray($seance['classe'] ?? null);
            $date = KlassciPayload::toStringOrNull($prog['date'] ?? null);

            return [
                'id' => $seance['id'] ?? null,
                'programmation' => [
                    'date' => $date,
                    // Format ISO complet, mais KLASSCI date heure_debut/heure_fin au jour
                    // courant → réaligner la date sur celle de la séance.
                    'heure_debut' => SeanceProgrammationNormalizer::alignDate(
                        KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null),
                        $date
                    ),
                    'heure_fin' => SeanceProgrammationNormalizer::alignDate(
                        KlassciPayload::toStringOrNull($prog['heure_fin'] ?? null),
                        $date
                    ),
                    'salle' => $prog['salle'] ?? null
                ],
                'salle' => $prog['salle'] ?? null, // Aussi en racine pour compatibilité
                'matiere' => [
                    'id' => $matiere['id'] ?? null,
                    'libelle' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A', // KLASSCI utilise 'nom' pas 'libelle'
                    'code' => $matiere['code'] ?? null
                ],
                'classe' => [
                    'id' => $classe['id'] ?? null,
                    'libelle' => $classe['nom'] ?? 'N/A'
                ],
                // `enseignant` est intentionnellement null sur ce chemin :
                // KLASSCI ne le fournit pas à ce niveau. Il est résolu côté frontend
                // via un appel séparé /enseignants/{matiere_id} si l'UI en a besoin.
                'enseignant' => null,
            ];
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
        return $seances->map(function ($seance) {
            // Calculer durée
            if (isset($seance['heure_debut']) && isset($seance['heure_fin'])) {
                $heureDebut = Carbon::parse($seance['date_seance'] . ' ' . $seance['heure_debut']);
                $heureFin = Carbon::parse($seance['date_seance'] . ' ' . $seance['heure_fin']);
                $seance['duree_minutes'] = $heureDebut->diffInMinutes($heureFin);
            }

            // Chercher infos visio dans la table locale
            $visioInfo = Seance::byKlassciId($seance['id'])->first();

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
    }
}
