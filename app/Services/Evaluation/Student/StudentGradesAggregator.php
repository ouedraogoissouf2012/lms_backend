<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;

/**
 * Agrégation des notes étudiantes par matière — extrait de
 * `EvaluationStudentController::myGrades` (split §5).
 *
 * Calcule la moyenne pondérée par coefficient au sein d'une matière,
 * et la moyenne générale across toutes les matières.
 *
 * Exclut les soumissions d'entraînement (feedback préfixé `[PRACTICE]`).
 *
 * D72, E4a : les matières viennent de la source de l'école
 * ({@see MatieresDesNotes}) : la clé de regroupement et le nom, dans l'espace de
 * l'école. C30 (règle A de l'utilisateur) : une évaluation aux résultats cachés
 * garde sa ligne, SANS la clé `note` (la forme de {@see ResultatsVisibles} sur la
 * liste), et n'entre dans aucune moyenne. Les moyennes suivent la règle unique des
 * agrégats sans mesure ({@see Moyennes}) : rien à moyenner, `null`.
 */
final class StudentGradesAggregator
{
    public function __construct(
        private readonly EvaluationGradingService $grading,
    ) {}

    /**
     * @return array{matieres: array<int, array<string, mixed>>, moyenne_generale: float|null, total_matieres: int, total_evaluations: int}
     */
    public function getGradesAggregatedByMatiere(User $user, MatieresDesNotes $matieres): array
    {
        $submissions = $this->fetchFinalSubmissions($user);
        $cles = array_values(array_unique($submissions
            ->map(static fn (EvaluationSubmission $s): int => $s->evaluation instanceof Evaluation ? $matieres->cleDe($s->evaluation) : 0)
            ->all()));
        $gradesByMatiere = $this->groupByMatiere($submissions, $matieres, $matieres->noms($user, $cles));
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
     * @return \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission>
     */
    private function fetchFinalSubmissions(User $user): \Illuminate\Database\Eloquent\Collection
    {
        // Les copies de CET élève, dans SON établissement : la portée de propriété,
        // et non l'identifiant KLASSCI seul, qui désignerait aussi les copies d'un
        // homonyme d'une autre école quand aucun tenant n'est résolu.
        /** @var \Illuminate\Database\Eloquent\Collection<int, EvaluationSubmission> $soumissions */
        $soumissions = EvaluationSubmission::query()->ownedBy($user)
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
     * @param  array<int, string>  $matieresData  clé de la source => nom
     * @return array<int, array<string, mixed>>  Indexé par la clé de la source
     */
    private function groupByMatiere(\Illuminate\Database\Eloquent\Collection $submissions, MatieresDesNotes $matieres, array $matieresData): array
    {
        $gradesByMatiere = [];
        foreach ($submissions as $submission) {
            $evaluation = $submission->evaluation;
            if (!$evaluation) {
                continue;
            }

            $matiereId = $matieres->cleDe($evaluation);
            $matiereNom = $matieresData[$matiereId] ?? $evaluation->matiere_nom ?? 'Matière inconnue';

            if (!isset($gradesByMatiere[$matiereId])) {
                $gradesByMatiere[$matiereId] = [
                    'matiere_id' => $matiereId,
                    'matiere_nom' => $matiereNom,
                    'evaluations' => [],
                    'moyenne' => null,
                    'total_evaluations' => 0,
                ];
            }

            $ligne = [
                'evaluation_id' => $evaluation->id,
                'titre' => $evaluation->titre,
                'type' => $evaluation->type,
                'note' => $submission->note_sur_20,
                'coefficient' => $evaluation->coefficient ?? 1,
                'date_evaluation' => $evaluation->date_evaluation,
                'date_soumission' => $submission->submitted_at,
                'temps_passe' => $submission->temps_passe_minutes,
            ];
            // C30 : la ligne reste, la clé de la note est RETIRÉE (forme de la liste).
            if (! ResultatsVisibles::pour($evaluation)) {
                unset($ligne['note']);
            }
            $gradesByMatiere[$matiereId]['evaluations'][] = $ligne;
        }
        return $gradesByMatiere;
    }

    /**
     * @param  array<int, array<string, mixed>>  $gradesByMatiere  Modifié par référence
     */
    private function computeMatiereAverages(array &$gradesByMatiere): void
    {
        foreach ($gradesByMatiere as &$matiere) {
            $comptees = [];
            foreach ($matiere['evaluations'] as $eval) {
                // Une note cachée (sans clé) ou absente n'entre dans aucune moyenne.
                if (isset($eval['note']) && is_numeric($eval['note']) && is_numeric($eval['coefficient'])) {
                    $comptees[] = [$eval['note'], $eval['coefficient']];
                }
            }
            $matiere['moyenne'] = Moyennes::ponderee($comptees);
            $matiere['total_evaluations'] = count($matiere['evaluations']);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $gradesList
     * @return array{0: float|null, 1: int}  [moyenne_generale, count_matieres]
     */
    private function computeOverallAverage(array $gradesList): array
    {
        $moyennes = [];
        $countMatieres = 0;
        foreach ($gradesList as $matiere) {
            if ($matiere['total_evaluations'] > 0) {
                $moyennes[] = is_float($matiere['moyenne']) ? $matiere['moyenne'] : null;
                $countMatieres++;
            }
        }

        return [Moyennes::simple($moyennes), $countMatieres];
    }
}
