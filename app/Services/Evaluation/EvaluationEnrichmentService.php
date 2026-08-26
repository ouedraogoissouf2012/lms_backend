<?php

declare(strict_types=1);

namespace App\Services\Evaluation;

use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Enrichit les évaluations avec les données KLASSCI (classes, matières, enseignant)
 * et les métadonnées calculées (statut effectif, compteurs, formats de date).
 *
 * Extrait verbatim de `EvaluationController::enrichEvaluationsWithKlassciData()` —
 * step 1/N du refactor god-controller (1676 lignes) suivant le plan d'audit
 * post-PR #149.
 *
 * ## DI strict (§1.6 D du manifeste)
 *
 * Constructeur injecte `KlassciProxyService` et `LoggerInterface` (PSR-3).
 * Pas de Facade `Log::` dans le code métier.
 *
 * ## Comportement préservé
 *
 * Aucun changement runtime : la logique d'enrichissement est déplacée verbatim
 * depuis le controller. Les 3 sites d'appel (`index`, `show`,
 * `getResultsByClass`) délèguent maintenant à `enrich()`.
 *
 * ## Fail-soft
 *
 * Si un appel KLASSCI échoue (classes/matieres indisponibles), le service
 * retourne les évaluations sans enrichissement (`$evaluations->toArray()`)
 * et log un warning. Choix volontaire pour ne pas casser la page liste si
 * KLASSCI est down — comportement hérité du controller original.
 *
 * @see app/Http/Controllers/API/EvaluationController.php (avant extraction)
 */
class EvaluationEnrichmentService
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
        private readonly EvaluationStateService $state,
    ) {}

    /**
     * Enrichit une Collection d'évaluations avec les données KLASSCI + métadonnées.
     *
     * @param  Collection<int, \App\Models\Evaluation>  $evaluations
     * @return array<int, array<string, mixed>>
     */
    public function enrich(Collection $evaluations, string $userToken): array
    {
        try {
            // Récupérer les classes et matières de KLASSCI (avec cache via KlassciProxyService)
            $klassciClasses = $this->klassciService->getClasses();
            $klassciMatieres = $userToken === ''
                ? ['data' => []]
                : $this->klassciService->getMatieres($userToken);

            // Créer des maps pour accès O(1)
            $classesMap = [];
            $klassciClassesData = $klassciClasses['data'] ?? null;
            if (is_array($klassciClassesData)) {
                foreach ($klassciClassesData as $classe) {
                    $classesMap[$classe['id']] = $classe;
                }
            }

            $matieresMap = [];
            $klassciMatieresData = $klassciMatieres['data'] ?? null;
            if (is_array($klassciMatieresData)) {
                foreach ($klassciMatieresData as $matiere) {
                    $matieresMap[$matiere['id']] = $matiere;
                }
            }

            return $evaluations->map(function ($evaluation) use ($classesMap, $matieresMap): array {
                $evalArray = $evaluation->toArray();

                $this->attachClasse($evalArray, $evaluation, $classesMap);
                $this->attachMatiere($evalArray, $evaluation, $matieresMap);
                $this->attachEnseignant($evalArray, $evaluation);
                $this->attachDateFormats($evalArray, $evaluation);
                $this->preserveDynamicFields($evalArray, $evaluation);
                $this->attachComputedMetadata($evalArray, $evaluation);

                return $evalArray;
            })->toArray();
        } catch (\Exception $e) {
            $this->logger->error('Erreur enrichissement évaluations', ['error' => $e->getMessage()]);

            // Fail-soft : retourner les évaluations sans enrichissement plutôt que de casser la page.
            return $evaluations->toArray();
        }
    }

    /**
     * Attache les détails de la classe à l'array enrichi.
     *
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     * @param  array<int, mixed>  $classesMap
     */
    private function attachClasse(array &$evalArray, Evaluation $evaluation, array $classesMap): void
    {
        if (isset($evaluation->klassci_classe_id) && isset($classesMap[$evaluation->klassci_classe_id])) {
            $evalArray['classe'] = $classesMap[$evaluation->klassci_classe_id];

            // Normaliser les champs : KLASSCI retourne 'name', on veut aussi 'nom' et 'libelle'.
            if (isset($evalArray['classe']['name']) && !empty($evalArray['classe']['name'])) {
                if (empty($evalArray['classe']['nom'])) {
                    $evalArray['classe']['nom'] = $evalArray['classe']['name'];
                }
                if (empty($evalArray['classe']['libelle'])) {
                    $evalArray['classe']['libelle'] = $evalArray['classe']['name'];
                }
            }

            // Fallback sur le nom stocké en base si KLASSCI ne fournit rien.
            if (empty($evalArray['classe']['nom']) && $evaluation->classe_nom) {
                $evalArray['classe']['nom'] = $evaluation->classe_nom;
                $evalArray['classe']['libelle'] = $evaluation->classe_nom;
            }
        } elseif ($evaluation->classe_nom) {
            // Évaluation standalone : pas de KLASSCI, utiliser le nom local.
            $evalArray['classe'] = [
                'id'      => $evaluation->klassci_classe_id,
                'name'    => $evaluation->classe_nom,
                'libelle' => $evaluation->classe_nom,
                'nom'     => $evaluation->classe_nom,
            ];
        } else {
            $evalArray['classe'] = null;
        }
    }

    /**
     * Attache les détails de la matière à l'array enrichi.
     *
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     * @param  array<int, mixed>  $matieresMap
     */
    private function attachMatiere(array &$evalArray, Evaluation $evaluation, array $matieresMap): void
    {
        if (isset($evaluation->klassci_matiere_id) && isset($matieresMap[$evaluation->klassci_matiere_id])) {
            $evalArray['matiere'] = $matieresMap[$evaluation->klassci_matiere_id];

            // KLASSCI ne retourne que 'nom' — ajouter 'libelle' si manquant.
            if (!isset($evalArray['matiere']['libelle']) || empty($evalArray['matiere']['libelle'])) {
                $evalArray['matiere']['libelle'] = $evalArray['matiere']['nom'] ?? $evaluation->matiere_nom;
            }

            if (empty($evalArray['matiere']['nom']) && $evaluation->matiere_nom) {
                $evalArray['matiere']['nom'] = $evaluation->matiere_nom;
                $evalArray['matiere']['libelle'] = $evaluation->matiere_nom;
            }
        } elseif ($evaluation->matiere_nom) {
            $evalArray['matiere'] = [
                'id'      => $evaluation->klassci_matiere_id,
                'nom'     => $evaluation->matiere_nom,
                'libelle' => $evaluation->matiere_nom,
            ];
        } else {
            $evalArray['matiere'] = null;
        }
    }

    /**
     * Attache les informations de l'enseignant depuis la table users locale.
     *
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     */
    private function attachEnseignant(array &$evalArray, Evaluation $evaluation): void
    {
        if ($evaluation->klassci_enseignant_id) {
            $enseignant = User::where('klassci_id', $evaluation->klassci_enseignant_id)->first();
            if ($enseignant) {
                $evalArray['enseignant'] = [
                    'id'    => $enseignant->klassci_id,
                    'nom'   => $enseignant->name,
                    'email' => $enseignant->email,
                ];
                $evalArray['enseignant_nom'] = $enseignant->name;
            } else {
                $evalArray['enseignant'] = null;
                $evalArray['enseignant_nom'] = 'Enseignant #' . $evaluation->klassci_enseignant_id;
            }
        } else {
            $evalArray['enseignant'] = null;
            $evalArray['enseignant_nom'] = null;
        }
    }

    /**
     * Ajoute les formats de date conviviaux.
     *
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     */
    private function attachDateFormats(array &$evalArray, Evaluation $evaluation): void
    {
        if ($evaluation->date_evaluation) {
            $evalArray['date_evaluation_formatted'] = $evaluation->date_evaluation->format('d/m/Y à H:i');
            $evalArray['date_evaluation_short'] = $evaluation->date_evaluation->format('d/m/Y');
        }
    }

    /**
     * Préserve les champs dynamiques ajoutés en amont (ex: `student_submission`
     * pushé par `EvaluationController::index` après batch query).
     *
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     */
    private function preserveDynamicFields(array &$evalArray, Evaluation $evaluation): void
    {
        if (isset($evaluation->student_submission)) {
            $evalArray['student_submission'] = $evaluation->student_submission;
        }
    }

    /**
     * Ajoute les compteurs et le statut effectif calculé.
     *
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     */
    private function attachComputedMetadata(array &$evalArray, Evaluation $evaluation): void
    {
        $evalArray['is_locked'] = $this->state->isLocked($evaluation);
        $evalArray['can_be_edited'] = $this->state->canBeEdited($evaluation);
        $evalArray['submissions_count'] = $evaluation->submissions()->count();
        $evalArray['questions_count'] = $evaluation->questions()->count();
        $evalArray['effective_status'] = $evaluation->getEffectiveStatus();
    }
}
