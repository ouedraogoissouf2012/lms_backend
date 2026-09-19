<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;

/**
 * Agrégation des notes étudiantes par matière — extrait de
 * `EvaluationStudentController::myGrades` (split §5).
 *
 * Calcule la moyenne pondérée par coefficient au sein d'une matière,
 * et la moyenne générale across toutes les matières.
 *
 * Exclut les soumissions d'entraînement (feedback préfixé `[PRACTICE]`).
 */
final class StudentGradesAggregator
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
        private readonly EvaluationGradingService $grading,
    ) {}

    /**
     * @return array{matieres: array<int, array<string, mixed>>, moyenne_generale: float, total_matieres: int, total_evaluations: int}
     */
    public function getGradesAggregatedByMatiere(User $user): array
    {
        $matieresData = $this->fetchMatieresNames($user);
        $submissions = $this->fetchFinalSubmissions($user);
        $gradesByMatiere = $this->groupByMatiere($submissions, $matieresData);
        $this->computeMatiereAverages($gradesByMatiere);

        $gradesList = array_values($gradesByMatiere);
        usort($gradesList, fn ($a, $b) => strcmp($a['matiere_nom'], $b['matiere_nom']));

        [$moyenneGenerale, $countMatieres] = $this->computeOverallAverage($gradesList);

        return [
            'matieres' => $gradesList,
            'moyenne_generale' => $moyenneGenerale,
            'total_matieres' => $countMatieres,
            'total_evaluations' => $submissions->count(),
        ];
    }

    /**
     * @return array<int, string>  Map matiere_id → nom KLASSCI
     */
    private function fetchMatieresNames(User $user): array
    {
        $token = $user->klassci_token;
        if (! is_string($token) || $token === '') {
            return [];
        }

        $matieresData = [];
        try {
            $matieresResponse = $this->klassciService->getMatieres($token);
            if (($matieresResponse['success'] ?? false) && isset($matieresResponse['data'])) {
                foreach ($matieresResponse['data'] as $matiere) {
                    $matieresData[$matiere['id']] = $matiere['nom'];
                }
            }
        } catch (\Exception $e) {
            $this->logger->warning('Impossible de récupérer les matières depuis KLASSCI', ['error' => $e->getMessage()]);
        }
        return $matieresData;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission>
     */
    private function fetchFinalSubmissions(User $user): \Illuminate\Database\Eloquent\Collection
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission> $soumissions */
        $soumissions = EvaluationSubmission::where('klassci_etudiant_id', $user->klassci_id)
            ->whereIn('status', ['soumis', 'corrige'])
            ->where(function ($q) {
                $q->whereNull('feedback')
                    ->orWhere('feedback', 'NOT LIKE', '[PRACTICE]%');
            })
            // `questions` est chargée ici : `noteEstFinale()` l'interroge pour
            // chaque soumission, et sans ce préchargement chaque note coûterait
            // une requête de plus.
            ->with(['evaluation' => fn ($query) => $query->where('is_published', true)->with('questions')])
            ->whereHas('evaluation', fn ($query) => $query->where('is_published', true))
            ->orderBy('submitted_at', 'desc')
            ->get();

        return $soumissions->filter(fn (EvaluationSubmission $s): bool => $this->noteEstFinale($s));
    }

    /**
     * La note ne peut-elle plus changer ?
     *
     * `corrige` : l'enseignant a tranché. `soumis` sans question à correction
     * manuelle : l'auto-correction est le dernier mot. `soumis` AVEC une
     * dissertation non notée : la note est DÉFLATÉE — la dissertation compte 0
     * au dénominateur tant qu'elle n'est pas corrigée — donc la montrer
     * annoncerait à l'élève un échec qui n'a pas eu lieu.
     *
     * C'est le critère que la synchro KLASSCI applique déjà pour refuser de
     * pousser (409). L'élève voit ainsi ce qui est, ou sera, transmis comme
     * officiel — et rien d'autre.
     */
    private function noteEstFinale(EvaluationSubmission $submission): bool
    {
        if ($submission->status === 'corrige') {
            return true;
        }

        $evaluation = $submission->evaluation;

        return $evaluation instanceof Evaluation
            && ! $this->grading->evaluationRequiresManualGrading($evaluation);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission>  $submissions
     * @param  array<int, string>  $matieresData
     * @return array<int, array<string, mixed>>  Indexé par matiere_id
     */
    private function groupByMatiere(\Illuminate\Database\Eloquent\Collection $submissions, array $matieresData): array
    {
        $gradesByMatiere = [];
        foreach ($submissions as $submission) {
            $evaluation = $submission->evaluation;
            if (!$evaluation) {
                continue;
            }

            $matiereId = $evaluation->klassci_matiere_id ?? 0;
            $matiereNom = $matieresData[$matiereId] ?? $evaluation->matiere_nom ?? 'Matière inconnue';

            if (!isset($gradesByMatiere[$matiereId])) {
                $gradesByMatiere[$matiereId] = [
                    'matiere_id' => $matiereId,
                    'matiere_nom' => $matiereNom,
                    'evaluations' => [],
                    'moyenne' => 0,
                    'total_evaluations' => 0,
                ];
            }

            $gradesByMatiere[$matiereId]['evaluations'][] = [
                'evaluation_id' => $evaluation->id,
                'titre' => $evaluation->titre,
                'type' => $evaluation->type,
                'note' => $submission->note_sur_20,
                'coefficient' => $evaluation->coefficient ?? 1,
                'date_evaluation' => $evaluation->date_evaluation,
                'date_soumission' => $submission->submitted_at,
                'temps_passe' => $submission->temps_passe_minutes,
            ];
        }
        return $gradesByMatiere;
    }

    /**
     * @param  array<int, array<string, mixed>>  $gradesByMatiere  Modifié par référence
     */
    private function computeMatiereAverages(array &$gradesByMatiere): void
    {
        foreach ($gradesByMatiere as &$matiere) {
            $totalPoints = 0;
            $totalCoef = 0;
            foreach ($matiere['evaluations'] as $eval) {
                $totalPoints += $eval['note'] * $eval['coefficient'];
                $totalCoef += $eval['coefficient'];
            }
            $matiere['moyenne'] = $totalCoef > 0 ? round($totalPoints / $totalCoef, 2) : 0;
            $matiere['total_evaluations'] = count($matiere['evaluations']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $gradesList
     * @return array{0: float, 1: int}  [moyenne_generale, count_matieres]
     */
    private function computeOverallAverage(array $gradesList): array
    {
        $totalMoyenne = 0;
        $countMatieres = 0;
        foreach ($gradesList as $matiere) {
            if ($matiere['total_evaluations'] > 0) {
                $totalMoyenne += $matiere['moyenne'];
                $countMatieres++;
            }
        }
        $moyenneGenerale = $countMatieres > 0 ? round($totalMoyenne / $countMatieres, 2) : 0;
        return [$moyenneGenerale, $countMatieres];
    }
}
