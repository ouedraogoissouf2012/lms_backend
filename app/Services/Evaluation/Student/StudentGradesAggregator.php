<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\DureeDeLaCopie;
use Illuminate\Database\Eloquent\Collection;

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
        private readonly CopiesFinales $copies,
        private readonly CoefficientsDesMatieres $coefficients,
    ) {}

    /**
     * @return array{matieres: array<int, array<string, mixed>>, moyenne_generale: float|null, total_matieres: int, total_evaluations: int}
     */
    public function getGradesAggregatedByMatiere(User $user, MatieresDesNotes $matieres): array
    {
        $submissions = $this->copies->deLEleve($user);
        $cles = array_values(array_unique($submissions
            ->map(static fn (EvaluationSubmission $s): int => $s->evaluation instanceof Evaluation ? $matieres->cleDe($s->evaluation) : 0)
            ->all()));
        $coefficients = $this->coefficients->pour($submissions->map(static fn (EvaluationSubmission $s): ?Evaluation => $s->evaluation)->filter()->values()->all());
        $gradesByMatiere = $this->groupByMatiere($submissions, $matieres, $matieres->noms($user, $cles), $coefficients);
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
     * @param  Collection<int, EvaluationSubmission>  $submissions
     * @param  array<int, string>  $matieresData  clé de la source => nom
     * @param  array<int, float>  $coefficients  le coefficient de la matière, par id d'évaluation
     * @return array<int, array<string, mixed>> Indexé par la clé de la source
     */
    private function groupByMatiere(Collection $submissions, MatieresDesNotes $matieres, array $matieresData, array $coefficients): array
    {
        $gradesByMatiere = [];
        foreach ($submissions as $submission) {
            $evaluation = $submission->evaluation;
            if (! $evaluation) {
                continue;
            }

            $matiereId = $matieres->cleDe($evaluation);
            $matiereNom = $matieresData[$matiereId] ?? $evaluation->matiere_nom ?? 'Matière inconnue';

            if (! isset($gradesByMatiere[$matiereId])) {
                $gradesByMatiere[$matiereId] = [
                    'matiere_id' => $matiereId,
                    'matiere_nom' => $matiereNom,
                    'evaluations' => [],
                    'moyenne' => null,
                    // #1676 : le coefficient de la MATIÈRE, dans la classe de sa copie la plus
                    // récente ({@see CoefficientsDesMatieres}) ; il pondère la moyenne générale.
                    'coefficient' => $coefficients[$evaluation->id] ?? CoefficientsDesMatieres::NEUTRE,
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
                // La durée de la copie (#901) : `temps_passe_minutes` n'existait pas.
                'temps_passe' => DureeDeLaCopie::enMinutes($submission->started_at, $submission->submitted_at),
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
     * #1676 : la moyenne générale est pondérée par le coefficient de la MATIÈRE, la règle du
     * bilan d'une classe (une seule règle : le rang suit la moyenne affichée à l'élève). Avec
     * tous les coefficients à 1, c'est exactement l'ancienne moyenne simple.
     *
     * @param  array<int, array<string, mixed>>  $gradesList
     * @return array{0: float|null, 1: int} [moyenne_generale, count_matieres]
     */
    private function computeOverallAverage(array $gradesList): array
    {
        $comptees = [];
        $countMatieres = 0;
        foreach ($gradesList as $matiere) {
            if ($matiere['total_evaluations'] > 0) {
                $countMatieres++;
                // Une matière sans note mesurée n'entre pas dans la moyenne (règle unique : `null`).
                if (is_float($matiere['moyenne']) && is_float($matiere['coefficient'])) {
                    $comptees[] = [$matiere['moyenne'], 1];
                }
            }
        }

        return [Moyennes::ponderee($comptees), $countMatieres];
    }
}
