<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Jobs\SyncKlassciClasse;
use App\Models\Seance;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\SeancesListQueryService;
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
 *   2. Source the séances of the window from KLASSCI's emploi du temps.
 *   3. Auto-create local Seance row when missing (visio disabled by default).
 *   4. Look up class effectif via KLASSCI.
 *   5. Map to the flat + nested legacy shape.
 *
 * ## La source a changé — et c'était LE défaut
 *
 * Les étapes 2 à 4 partaient jusqu'ici d'un pool `matieres/{id}`, dont on lisait
 * `data.seances_programmees`. Cette clé est **toujours vide** chez KLASSCI :
 * `matieres/3` renvoyait `seances_programmees: []` en annonçant, dans le même
 * corps, `statistiques.seances.total_programmees: 28` (mesuré le 2026-09-05).
 * L'enseignant voyait « Séances 0 ».
 *
 * {@see KlassciEmploiTempsSeances} interroge l'emploi du temps, qui répond
 * vraiment. Le pool `matieres/{id}` disparaît par la même occasion : il ne
 * servait QU'À lire cette clé morte. Autant d'appels HTTP en moins par
 * affichage — la rafale même qui arme le filtre anti-abus de l'hébergement de
 * KLASSCI.
 *
 * @see SeancesListQueryService (orchestrator — décide la fenêtre de dates)
 */
final class TeachingSeancesFetcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
        private readonly SeanceCacheDataBuilder $cacheBuilder,
        private readonly KlassciEmploiTempsSeances $emploiTemps,
    ) {}

    /**
     * La fenêtre est imposée par l'appelant : `emploi-temps` interrogé sans
     * `date_debut`/`date_fin` ne rend que la semaine courante (mesuré).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function fetch(User $user, string $klassciToken, string $dateDebut, string $dateFin): Collection
    {
        $this->logger->info('Récupération séances enseignant', [
            'user_id' => $user->id,
            'klassci_id' => $user->klassci_id,
        ]);

        $matieres = $this->teacherMatieres($klassciToken);

        // Le filtre par matière est LOCAL : KLASSCI accepte `matiere_id` et
        // l'ignore (mesuré — il renvoyait les séances d'autres matières).
        $seancesParMatiere = $this->emploiTemps->fetchByMatiere(
            $klassciToken,
            KlassciPayload::uniqueIntIds($matieres, fn (array $m): ?int => KlassciPayload::toInt($m['id'] ?? null)),
            $dateDebut,
            $dateFin,
        );

        // PERF (#135) : pré-charger en UN pool dédupliqué tous les effectifs de
        // classe — était 1 appel `classes/{id}` séquentiel PAR séance.
        $classesDetails = $this->klassciService->fetchManyClassesDetails(
            $this->collectClasseIds($seancesParMatiere),
            $klassciToken
        );

        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);

        foreach ($matieres as $matiere) {
            $matiereId = KlassciPayload::toInt($matiere['id'] ?? null);
            if ($matiereId === null) {
                continue;
            }

            $seances = $seances->concat($this->enrich(
                $seancesParMatiere[$matiereId] ?? [],
                $matiere,
                $user,
                $klassciToken,
                $classesDetails,
            ));
        }

        // Trier par date/heure
        return $seances->sortBy('date_seance')->values();
    }

    /**
     * Les matières de l'enseignant, via le teacher-dashboard — la seule source
     * qui les rende réellement scopées à lui (le catalogue `/matieres` en
     * renvoyait 452, toutes institutions confondues).
     *
     * @return array<int, array<string, mixed>>
     */
    private function teacherMatieres(string $klassciToken): array
    {
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/teacher-dashboard',
            'GET'
        );

        return KlassciPayload::listOfArrays(
            KlassciPayload::asArray($dashboard['data'] ?? null)['matieres'] ?? null
        );
    }

    /**
     * Assemble les séances d'UNE matière : miroir local, effectif, mise en forme.
     *
     * @param  list<array<string, mixed>>  $seances
     * @param  array<string, mixed>  $matiere
     * @param  array<int, array<string, mixed>>  $classesDetails
     * @return Collection<int, array<string, mixed>>
     */
    private function enrich(array $seances, array $matiere, User $user, string $klassciToken, array $classesDetails): Collection
    {
        return collect($seances)->map(fn (array $seance): array => $this->mapSeance(
            $seance,
            $matiere,
            $user,
            $this->ensureLocalSeanceExists($seance, $matiere, $user, $klassciToken),
            KlassciPayload::classeEffectif($seance, $classesDetails),
        ));
    }

    /**
     * Les IDs de classe (dédupliqués) de toutes les séances de la fenêtre, pour
     * les pré-charger en un seul pool.
     *
     * @param  array<int, list<array<string, mixed>>>  $seancesParMatiere
     * @return array<int>
     */
    private function collectClasseIds(array $seancesParMatiere): array
    {
        $ids = [];
        foreach ($seancesParMatiere as $seances) {
            $ids = array_merge($ids, KlassciPayload::uniqueIntIds($seances, KlassciPayload::classeIdFor(...)));
        }

        return array_values(array_unique($ids));
    }

    /**
     * Enregistrer la séance KLASSCI en local si pas encore présente.
     * IMPORTANT: La visio n'est PAS activée automatiquement —
     * l'enseignant doit explicitement activer la visio pour qu'elle soit visible aux étudiants.
     *
     * @param  array<string, mixed>  $seance
     * @param  array<string, mixed>  $matiere
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
            // La classe est synchronisée « pour les notifications futures » — un
            // besoin FUTUR, exécuté jusqu'ici sur le chemin critique d'un
            // enseignant qui attend sa liste. L'appel était synchrone, son retour
            // jeté, et il rejouait un `classes/{id}` par séance alors que
            // `fetchManyClassesDetails` venait de récupérer ces mêmes classes en
            // un pool. On le reporte : un job peut attendre et réessayer, pas un
            // humain.
            if ($classeId !== null && $user->institution_id !== null) {
                SyncKlassciClasse::dispatch($classeId, $user->id, (int) $user->institution_id);
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
                'klassci_enseignant_id' => $user->klassci_id,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Erreur création entrée séance locale', [
                'seance_id' => $klassciSeanceId,
                'error' => $e->getMessage(),
            ]);
        }

        return $visioData;
    }

    /**
     * @param  array<string, mixed>  $seance
     * @param  array<string, mixed>  $matiere
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
                'salle' => $prog['salle'] ?? null,
            ],
            'matiere' => [
                'id' => $matiere['id'] ?? null,
                'nom' => $matiere['nom'] ?? $matiere['libelle'] ?? 'N/A',
                'code' => $matiere['code'] ?? null,
            ],
            'classe' => [
                'id' => $classe['id'] ?? null,
                'nom' => $classe['nom'] ?? 'N/A',
                'effectif' => $classeEffectif,
            ],
            'enseignant' => [
                'id' => $user->klassci_id,
                'nom' => $user->name,
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
                'participants_count' => $visioData->current_participants_count ?? 0,
            ] : null,
        ];
    }
}
