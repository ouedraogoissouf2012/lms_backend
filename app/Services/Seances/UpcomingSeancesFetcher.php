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
    public function fetch(User $user, string $klassciToken, string $dateDebut, string $dateFin, ?int $classeId): Collection
    {
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

            foreach ($matieres as $matiere) {
                $matiereId = $matiere['id'];

                try {
                    $matiereDetails = $this->klassciService->requestWithUserToken(
                        $klassciToken,
                        "matieres/{$matiereId}",
                        'GET'
                    );

                    $seancesProgrammees = collect($matiereDetails['data']['seances_programmees'] ?? []);
                    $seancesFiltrees = $this->filterSeances($seancesProgrammees, $user, $dateDebut, $dateFin, $classeId);
                    $seancesMapped = $this->mapSeances($seancesFiltrees, $matiere);
                    $seances = $seances->concat($seancesMapped);

                } catch (\Exception $matiereError) {
                    $this->logger->warning("Erreur matière {$matiereId}", ['error' => $matiereError->getMessage()]);
                }
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
        return $seances->map(function ($seance) use ($matiere) {
            return [
                'id' => $seance['id'],
                'programmation' => [
                    'date' => $seance['programmation']['date'],
                    'heure_debut' => $seance['programmation']['heure_debut'], // Garder le format complet ISO
                    'heure_fin' => $seance['programmation']['heure_fin'],
                    'salle' => $seance['programmation']['salle'] ?? null
                ],
                'salle' => $seance['programmation']['salle'] ?? null, // Aussi en racine pour compatibilité
                'matiere' => [
                    'id' => $matiere['id'],
                    'libelle' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A', // KLASSCI utilise 'nom' pas 'libelle'
                    'code' => $matiere['code'] ?? null
                ],
                'classe' => [
                    'id' => $seance['classe']['id'] ?? null,
                    'libelle' => $seance['classe']['nom'] ?? 'N/A'
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
