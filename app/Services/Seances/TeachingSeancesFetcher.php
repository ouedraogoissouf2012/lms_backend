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
                $classeEffectif = $this->resolveClasseEffectif($seance, $classesDetails);
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
            foreach (KlassciPayload::asList($data['seances_programmees'] ?? null) as $seance) {
                $classe = KlassciPayload::asArray(KlassciPayload::asArray($seance)['classe'] ?? null);
                $id = KlassciPayload::toInt($classe['id'] ?? null);
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
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
     * Résout l'effectif d'une classe depuis le map pré-chargé en pool, sans appel
     * réseau supplémentaire. Classe absente (ID échoué dans le pool) → 0.
     *
     * @param array<string, mixed> $seance
     * @param array<int, array<string, mixed>> $classesDetails
     */
    private function resolveClasseEffectif(array $seance, array $classesDetails): int
    {
        $classeId = KlassciPayload::toInt(KlassciPayload::asArray($seance['classe'] ?? null)['id'] ?? null);
        if ($classeId === null) {
            return 0;
        }

        $classe = KlassciPayload::asArray(
            KlassciPayload::asArray($classesDetails[$classeId]['data'] ?? null)['classe'] ?? null
        );
        $occupees = $classe['places_occupees'] ?? 0;

        return is_int($occupees) ? $occupees : (is_numeric($occupees) ? (int) $occupees : 0);
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
