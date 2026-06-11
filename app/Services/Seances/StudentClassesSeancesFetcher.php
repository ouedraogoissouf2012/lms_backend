<?php

declare(strict_types=1);

namespace App\Services\Seances;

use App\Models\Seance;
use App\Models\SeanceUserHidden;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * StudentClassesSeancesFetcher — KLASSCI walker for the student "my-classes" listing.
 *
 * Extracted from `LMSSeancesQueryController::myClassesSeances` (~196 lines)
 * during split-1.
 *
 * ## Responsibility (SRP)
 *
 * Builds the student's class séances listing:
 *   1. Read student dashboard → cours[] + classe.id.
 *   2. For each cours, walk `seances_programmees`.
 *   3. Keep only séances of the student's class.
 *   4. Drop archived + user-hidden séances.
 *   5. Resolve enseignant name (local DB lookup with fallback).
 *   6. Map to the legacy student-side shape (with nested visio).
 *
 * ## Contract
 *
 * Returns either:
 *   - `null` if no class can be resolved for the student (caller renders 404).
 *   - A collection of mapped séances (possibly empty) otherwise.
 *
 * @see \App\Services\SeancesListQueryService (orchestrator)
 */
final class StudentClassesSeancesFetcher
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Result tag returned when the student has no matières available in the dashboard.
     */
    public const RESULT_NO_MATIERES = 'no-matieres';

    /**
     * @return Collection<int, array<string, mixed>>|self::RESULT_NO_MATIERES|null
     *         - `null` : student has no resolvable class (caller renders 404).
     *         - `self::RESULT_NO_MATIERES` : student has a class but no matières.
     *         - Collection : normal happy path (possibly empty).
     */
    public function fetch(User $user, string $klassciToken): Collection|string|null
    {
        $this->logger->info('Récupération séances étudiant', [
            'user_id' => $user->id,
            'klassci_id' => $user->klassci_id
        ]);

        // Récupérer le dashboard étudiant pour avoir sa classe
        $dashboard = $this->klassciService->requestWithUserToken(
            $klassciToken,
            'me/dashboard',
            'GET'
        );

        $classeId = $dashboard['data']['classe']['id'] ?? null;

        if (!$classeId) {
            return null;
        }

        // Utiliser les matières du dashboard au lieu de faire un nouvel appel API
        $coursFromDashboard = $dashboard['data']['cours'] ?? [];

        /** @var Collection<int, array<string, mixed>> $seances */
        $seances = collect([]);

        if (empty($coursFromDashboard)) {
            return self::RESULT_NO_MATIERES;
        }

        foreach ($coursFromDashboard as $matiere) {
            try {
                // Gérer les différents formats de données (id ou matiere_id ou matiere.id)
                $matiereId = $matiere['id'] ?? $matiere['matiere_id'] ?? $matiere['matiere']['id'] ?? null;

                if (!$matiereId) {
                    $this->logger->warning('[LMS] Matière sans ID valide', ['matiere' => $matiere]);
                    continue;
                }

                $matiereDetails = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$matiereId}",
                    'GET'
                );

                $seancesProgrammees = collect($matiereDetails['data']['seances_programmees'] ?? []);
                $seancesClasse = $this->filterForStudent($seancesProgrammees, $classeId, $user);
                $seancesEnrichies = $this->mapStudentSeances($seancesClasse, $matiere, $matiereId);

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
     * Garde uniquement les séances de la classe de l'étudiant, en excluant
     * les séances archivées et celles que l'étudiant a masquées.
     *
     * @param Collection<int, array<string, mixed>> $seances
     * @return Collection<int, array<string, mixed>>
     */
    private function filterForStudent(Collection $seances, int $classeId, User $user): Collection
    {
        // Filtrer uniquement les séances de la classe de l'étudiant
        $seancesClasse = $seances->filter(function ($seance) use ($classeId) {
            return ($seance['classe']['id'] ?? null) == $classeId;
        });

        // IMPORTANT: Pour les étudiants, on montre TOUTES les séances de leur classe
        // L'emploi du temps doit être complet. On filtre seulement:
        // 1. Les séances archivées (is_active = false)
        // 2. Les séances masquées par l'étudiant
        return $seancesClasse->filter(function ($seance) use ($user) {
            $localSeance = Seance::where('klassci_seance_id', $seance['id'])->first();

            // Si la séance existe en local et est archivée, ne pas la montrer
            if ($localSeance && !$localSeance->is_active) {
                return false;
            }

            // Si la séance existe en local et est masquée par l'étudiant, ne pas la montrer
            if ($localSeance && SeanceUserHidden::isHidden($localSeance->id, $user->id)) {
                return false;
            }

            // Montrer la séance (même si pas de visio activée - c'est l'emploi du temps)
            return true;
        });
    }

    /**
     * @param Collection<int, array<string, mixed>> $seancesClasse
     * @param array<string, mixed> $matiere
     * @return Collection<int, array<string, mixed>>
     */
    private function mapStudentSeances(Collection $seancesClasse, array $matiere, int $matiereId): Collection
    {
        return $seancesClasse->map(function ($seance) use ($matiere, $matiereId) {
            // Chercher la séance dans la BDD locale par klassci_seance_id
            // IMPORTANT: Les étudiants ne voient que les séances actives (is_active = true)
            $visioData = Seance::where('klassci_seance_id', $seance['id'])
                ->where('is_active', true)
                ->first();

            $enseignantNom = $this->resolveEnseignantNom($seance, $matiere, $visioData);

            // IMPORTANT: Utiliser la structure programmation comme les autres endpoints
            return [
                'id' => $seance['id'],
                'programmation' => [
                    'date' => $seance['programmation']['date'] ?? null,
                    'heure_debut' => $seance['programmation']['heure_debut'] ?? null,
                    'heure_fin' => $seance['programmation']['heure_fin'] ?? null,
                    'salle' => $seance['programmation']['salle'] ?? null
                ],
                'salle' => $seance['programmation']['salle'] ?? null, // Aussi en racine pour compatibilité
                'matiere' => [
                    'id' => $matiereId,
                    'nom' => $matiere['nom'] ?? $matiere['name'] ?? $matiere['libelle'] ?? 'N/A',
                    'code' => $matiere['code'] ?? null
                ],
                'classe' => [
                    'id' => $seance['classe']['id'] ?? null,
                    'nom' => $seance['classe']['nom'] ?? 'N/A'
                ],
                'enseignant' => [
                    'nom' => $enseignantNom
                ],
                'visio' => $visioData ? [
                    // Une séance est considérée "avec visio" si:
                    // 1. visio_enabled = true OU
                    // 2. Elle a un statut actif (programmee, active)
                    'enabled' => $visioData->visio_enabled ||
                                in_array($visioData->visio_status, ['programmee', 'active']),
                    'status' => $visioData->visio_status,
                    'room_id' => $visioData->visio_room_id,
                    'started_at' => $visioData->visio_started_at,
                    'participants_count' => $visioData->current_participants_count ?? 0
                ] : null
            ];
        });
    }

    /**
     * @param array<string, mixed> $seance
     * @param array<string, mixed> $matiere
     */
    private function resolveEnseignantNom(array $seance, array $matiere, ?Seance $visioData): string
    {
        if ($visioData && $visioData->enseignant_nom) {
            return $visioData->enseignant_nom;
        }

        // Fallback: chercher une autre séance de la même matière pour récupérer l'enseignant
        $matiereNom = $matiere['nom'] ?? $matiere['name'] ?? $matiere['libelle'] ?? null;
        if (!$matiereNom) {
            return 'Non assigné';
        }

        $autreSeance = Seance::where('matiere_nom', $matiereNom)
            ->whereNotNull('enseignant_nom')
            ->first();

        if (!$autreSeance) {
            return 'Non assigné';
        }

        $this->logger->info('Enseignant récupéré depuis autre séance même matière', [
            'seance_id' => $seance['id'],
            'matiere' => $matiereNom,
            'enseignant' => $autreSeance->enseignant_nom
        ]);

        return $autreSeance->enseignant_nom;
    }
}
