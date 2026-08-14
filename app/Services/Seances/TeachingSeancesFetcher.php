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
        private readonly SeanceCacheDataBuilder $cacheBuilder,
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

        $matieres = collect(KlassciPayload::listOfArrays(
            KlassciPayload::asArray($dashboard['data'] ?? null)['matieres'] ?? null
        ));
        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);

        // PERF (#135) : paralléliser les détails matières — était N appels
        // `matieres/{id}` séquentiels (N+1). Le pool gère memo + cache + erreurs
        // partielles (ID échoué = absent du map → matière silencieusement omise,
        // log émis par le batch fetcher : même sémantique que l'ancien try/catch).
        $matiereIds = [];
        foreach ($matieres as $matiere) {
            $id = KlassciPayload::toInt(KlassciPayload::asArray($matiere)['id'] ?? null);
            if ($id !== null) {
                $matiereIds[] = $id;
            }
        }
        $matiereIds = array_values(array_unique($matiereIds));
        $matieresDetails = $this->klassciService->fetchManyMatieresDetails($matiereIds, $klassciToken);

        // PERF (#135) : pré-charger en UN pool dédupliqué tous les effectifs de
        // classe — était 1 appel `classes/{id}` séquentiel PAR séance.
        $classesDetails = $this->klassciService->fetchManyClassesDetails(
            $this->collectClasseIds($matieresDetails),
            $klassciToken
        );

        foreach ($matieres as $matiere) {
            $matiereArr = KlassciPayload::asArray($matiere);
            $matiereId = KlassciPayload::toInt($matiereArr['id'] ?? null);
            if ($matiereId === null) {
                continue;
            }

            $matiereDetails = $matieresDetails[$matiereId] ?? null;
            if ($matiereDetails === null) {
                continue; // matière échouée dans le pool — log déjà émis par le batch fetcher
            }

            $seancesProgrammees = collect(
                KlassciPayload::listOfArrays(KlassciPayload::asArray($matiereDetails['data'] ?? null)['seances_programmees'] ?? null)
            );

            $seancesEnrichies = $seancesProgrammees->map(function (array $seance) use ($matiereArr, $user, $klassciToken, $classesDetails) {
                $visioData = $this->ensureLocalSeanceExists($seance, $matiereArr, $user, $klassciToken);
                $classeEffectif = KlassciPayload::classeEffectif($seance, $classesDetails);
                return $this->mapSeance($seance, $matiereArr, $user, $visioData, $classeEffectif);
            });

            $seances = $seances->concat($seancesEnrichies);
        }

        // Trier par date/heure
        return $seances->sortBy('date_seance')->values();
    }

    /**
     * Collecte les IDs de classe (dédupliqués) de toutes les séances programmées
     * pour les pré-charger en un seul pool.
     *
     * @param  array<int, array<string, mixed>>  $matieresDetails
     * @return array<int>
     */
    private function collectClasseIds(array $matieresDetails): array
    {
        $ids = [];
        foreach ($matieresDetails as $details) {
            $data = KlassciPayload::asArray(KlassciPayload::asArray($details)['data'] ?? null);
            $seances = KlassciPayload::listOfArrays($data['seances_programmees'] ?? null);
            $ids = array_merge($ids, KlassciPayload::uniqueIntIds($seances, KlassciPayload::classeIdFor(...)));
        }

        return array_values(array_unique($ids));
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
        $klassciSeanceId = KlassciPayload::toInt($seance['id'] ?? null);
        $classeId = KlassciPayload::toInt(KlassciPayload::asArray($seance['classe'] ?? null)['id'] ?? null);

        $visioData = Seance::where('klassci_seance_id', $klassciSeanceId)->withConnectedParticipantsCount()->first();
        $cacheData = $this->cacheBuilder->build($seance, $matiere, $user);

        if ($visioData) {
            $this->cacheBuilder->applyTo($visioData, $cacheData);
            return $visioData;
        }

        try {
            // Synchroniser la classe pour les notifications futures
            if ($classeId !== null) {
                $this->classeSyncService->syncClasseById($classeId, $klassciToken);
            }

            // Créer l'entrée locale SANS activer la visio
            // L'enseignant devra cliquer sur "Activer la visio" pour la rendre visible
            $visioData = Seance::create($cacheData + [
                'klassci_seance_id' => $klassciSeanceId,
                'visio_enabled' => false,  // Désactivé par défaut - l'enseignant doit activer
                'visio_type' => 'jitsi',
                'visio_status' => null,    // Pas de statut tant que non activé
                'visio_room_id' => null,   // Room créée lors de l'activation
                'visio_active' => false,
                'created_by' => $user->id,
            ]);

            $this->logger->info('Séance Klassci détectée - En attente d\'activation par l\'enseignant', [
                'seance_id' => $klassciSeanceId,
                'klassci_enseignant_id' => $user->klassci_id
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur création entrée séance locale', [
                'seance_id' => $klassciSeanceId,
                'error' => $e->getMessage()
            ]);
        }

        return $visioData;
    }

    /**
     * @param array<string, mixed> $seance
     * @param array<string, mixed> $matiere
     * @return array<string, mixed>
     */
    private function mapSeance(array $seance, array $matiere, User $user, ?Seance $visioData, int $classeEffectif): array
    {
        $prog = KlassciPayload::asArray($seance['programmation'] ?? null);
        $classe = KlassciPayload::asArray($seance['classe'] ?? null);
        $date = KlassciPayload::toStringOrNull($prog['date'] ?? null);
        $heureDebut = KlassciPayload::toStringOrNull($prog['heure_debut'] ?? null);
        $heureFin = KlassciPayload::toStringOrNull($prog['heure_fin'] ?? null);

        return [
            'id' => $seance['id'] ?? null,
            // Garder compatibilité ancienne structure
            'date_seance' => $date,
            'heure_debut' => $heureDebut !== null ? substr($heureDebut, 11, 5) : null,
            'heure_fin' => $heureFin !== null ? substr($heureFin, 11, 5) : null,
            'salle' => $prog['salle'] ?? null,
            // Ajouter structure programmation pour cohérence avec autres endpoints
            'programmation' => [
                'date' => $date,
                // KLASSCI date heure_debut/heure_fin au jour courant → on réaligne sur la date de la séance.
                'heure_debut' => SeanceProgrammationNormalizer::alignDate($heureDebut, $date),
                'heure_fin' => SeanceProgrammationNormalizer::alignDate($heureFin, $date),
                'salle' => $prog['salle'] ?? null
            ],
            'matiere' => [
                'id' => $matiere['id'] ?? null,
                'nom' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A',
                'code' => $matiere['code'] ?? null
            ],
            'classe' => [
                'id' => $classe['id'] ?? null,
                'nom' => $classe['nom'] ?? 'N/A',
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
