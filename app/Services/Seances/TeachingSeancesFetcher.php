<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\User;
use App\Services\ClasseSyncService;
use App\Services\KlassciProxyService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * TeachingSeancesFetcher — KLASSCI walker for "my teaching séances" (enseignant).
 *
 * Extracted from `LMSSeancesQueryController::myTeachingSeances` (~188 lines)
 * during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Builds the teacher's séances listing:
 *   1. Walk teacher-dashboard matières.
 *   2. For each matière, walk `seances_programmees`.
 *   3. Auto-create local Seance row when missing (visio disabled by default).
 *   4. Look up class effectif via KLASSCI.
 *   5. Map to the flat + nested legacy shape.
 *
 * @see \App\Services\SeancesListQueryService (orchestrator)
 */
final class TeachingSeancesFetcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
        private readonly ClasseSyncService $classeSyncService,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function fetch(User $user, string $klassciToken): Collection
    {
        $this->logger->info('Récupération séances enseignant', [
            'user_id' => $user->id,
            'klassci_id' => $user->klassci_id
        ]);

        // Utiliser le teacher-dashboard qui contient les matières de l'enseignant
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        $matieres = collect($dashboard['data']['matieres'] ?? []);
        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);

        foreach ($matieres as $matiere) {
            try {
                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiere['id']}",
                    'GET'
                );

                $seancesProgrammees = collect($matiereDetails['data']['seances_programmees'] ?? []);

                $seancesEnrichies = $seancesProgrammees->map(function ($seance) use ($matiere, $user, $klassciToken) {
                    $visioData = $this->ensureLocalSeanceExists($seance, $matiere, $user, $klassciToken);
                    $classeEffectif = $this->fetchClasseEffectif($seance, $klassciToken);
                    return $this->mapSeance($seance, $matiere, $user, $visioData, $classeEffectif);
                });

                $seances = $seances->concat($seancesEnrichies);
            } catch (\Exception $e) {
                $this->logger->warning('Erreur récupération séances matière', [
                    'matiere_id' => $matiere['id'],
                    'error' => $e->getMessage()
                ]);
            }
        }

        // Trier par date/heure
        return $seances->sortBy('date_seance')->values();
    }

    /**
     * Enregistrer la séance KLASSCI en local si pas encore présente.
     * IMPORTANT: La visio n'est PAS activée automatiquement —
     * l'enseignant doit explicitement activer la visio pour qu'elle soit visible aux étudiants.
     *
     * @param array<string, mixed> $seance
     * @param array<string, mixed> $matiere
     */
    private function ensureLocalSeanceExists(array $seance, array $matiere, User $user, string $klassciToken): ?Seance
    {
        $visioData = Seance::where('klassci_seance_id', $seance['id'])->withConnectedParticipantsCount()->first();
        if ($visioData) {
            return $visioData;
        }

        try {
            // Synchroniser la classe pour les notifications futures
            if (isset($seance['classe']['id'])) {
                $this->classeSyncService->syncClasseById(
                    $seance['classe']['id'],
                    $klassciToken
                );
            }

            // Créer l'entrée locale SANS activer la visio
            // L'enseignant devra cliquer sur "Activer la visio" pour la rendre visible
            $visioData = Seance::create([
                'klassci_seance_id' => $seance['id'],
                'klassci_matiere_id' => $matiere['id'],
                'klassci_classe_id' => $seance['classe']['id'] ?? null,
                'klassci_enseignant_id' => $user->klassci_id,
                'enseignant_nom' => $user->name,
                'matiere_nom' => $matiere['nom'] ?? $matiere['libelle'] ?? null,
                'visio_enabled' => false,  // Désactivé par défaut - l'enseignant doit activer
                'visio_type' => 'jitsi',
                'visio_status' => null,    // Pas de statut tant que non activé
                'visio_room_id' => null,   // Room créée lors de l'activation
                'visio_active' => false,
                'created_by' => $user->id,
            ]);

            $this->logger->info('Séance Klassci détectée - En attente d\'activation par l\'enseignant', [
                'seance_id' => $seance['id'],
                'klassci_enseignant_id' => $user->klassci_id
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur création entrée séance locale', [
                'seance_id' => $seance['id'],
                'error' => $e->getMessage()
            ]);
        }

        return $visioData;
    }

    /**
     * @param array<string, mixed> $seance
     */
    private function fetchClasseEffectif(array $seance, string $klassciToken): int
    {
        if (!isset($seance['classe']['id'])) {
            return 0;
        }

        try {
            $classeDetails = $this->klassciService->requestWithUserToken(
                $klassciToken,
                "classes/{$seance['classe']['id']}",
                'GET'
            );
            return $classeDetails['data']['classe']['places_occupees'] ?? 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $seance
     * @param array<string, mixed> $matiere
     * @return array<string, mixed>
     */
    private function mapSeance(array $seance, array $matiere, User $user, ?Seance $visioData, int $classeEffectif): array
    {
        return [
            'id' => $seance['id'],
            // Garder compatibilité ancienne structure
            'date_seance' => $seance['programmation']['date'] ?? null,
            'heure_debut' => isset($seance['programmation']['heure_debut'])
                ? substr($seance['programmation']['heure_debut'], 11, 5)
                : null,
            'heure_fin' => isset($seance['programmation']['heure_fin'])
                ? substr($seance['programmation']['heure_fin'], 11, 5)
                : null,
            'salle' => $seance['programmation']['salle'] ?? null,
            // Ajouter structure programmation pour cohérence avec autres endpoints
            'programmation' => [
                'date' => $seance['programmation']['date'] ?? null,
                // KLASSCI date heure_debut/heure_fin au jour courant → on réaligne sur la date de la séance.
                'heure_debut' => SeanceProgrammationNormalizer::alignDate(
                    $seance['programmation']['heure_debut'] ?? null,
                    $seance['programmation']['date'] ?? null
                ),
                'heure_fin' => SeanceProgrammationNormalizer::alignDate(
                    $seance['programmation']['heure_fin'] ?? null,
                    $seance['programmation']['date'] ?? null
                ),
                'salle' => $seance['programmation']['salle'] ?? null
            ],
            'matiere' => [
                'id' => $matiere['id'],
                'nom' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A',
                'code' => $matiere['code'] ?? null
            ],
            'classe' => [
                'id' => $seance['classe']['id'] ?? null,
                'nom' => $seance['classe']['nom'] ?? 'N/A',
                'effectif' => $classeEffectif
            ],
            'enseignant' => [
                'id' => $user->klassci_id,
                'nom' => $user->name
            ],
            // Infos visio (structure plate pour compatibilité frontend)
            'visio_enabled' => $visioData ? $visioData->visio_enabled : false,
            'visio_active' => $visioData ? $visioData->visio_active : false,
            'visio_status' => $visioData ? $visioData->visio_status : null,
            'visio_room_id' => $visioData ? $visioData->visio_room_id : null,
            // Objet visio (structure imbriquée pour compatibilité)
            'visio' => $visioData ? [
                'enabled' => $visioData->visio_enabled,
                'active' => $visioData->visio_active,
                'status' => $visioData->visio_status,
                'room_id' => $visioData->visio_room_id,
                'started_at' => $visioData->visio_started_at,
                'ended_at' => $visioData->visio_ended_at,
                'participants_count' => $visioData->current_participants_count ?? 0
            ] : null
        ];
    }
}
