<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\DispenseDEvaluation;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\Classe\NotesDeLaClasse;
use App\Services\Evaluation\EvaluationGradingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * #1676 : « la note qui compte », en UN seul lieu, pour « Mes notes » et pour le bilan d'une
 * classe. Sortie telle quelle de {@see StudentGradesAggregator} : aucune règle ne change.
 *
 * Une copie compte quand elle est remise (`soumis` ou `corrige`), hors entraînement
 * (`feedback` préfixé `[PRACTICE]`), sur une évaluation publiée, et que sa note est FINALE :
 * corrigée, ou remise sans question à correction manuelle.
 */
final class CopiesFinales
{
    public function __construct(private readonly EvaluationGradingService $grading) {}

    /**
     * Les copies finales de CET élève, dans SON établissement : la portée de propriété, et non
     * l'identifiant KLASSCI seul, qui désignerait aussi les copies d'un homonyme d'une autre
     * école quand aucun tenant n'est résolu. Les plus récentes d'abord.
     *
     * #1715 : UNE copie par évaluation, la plus récente des copies finales. Une évaluation à
     * plusieurs tentatives (`max_attempts`) ne pèse qu'une fois, comme dans le bilan de la
     * classe ({@see NotesDeLaClasse::copieDe()}).
     *
     * @return Collection<int, EvaluationSubmission>
     */
    public function deLEleve(User $eleve): Collection
    {
        return $this->finales(EvaluationSubmission::query()->ownedBy($eleve))
            ->unique(static fn (EvaluationSubmission $copie): int => (int) $copie->evaluation_id)
            ->values();
    }

    /**
     * Les copies finales de ces évaluations, dans cet établissement : le bilan d'une classe.
     *
     * @param  list<int>  $evaluations
     * @return Collection<int, EvaluationSubmission>
     */
    public function desEvaluations(array $evaluations, int $etablissement): Collection
    {
        if ($evaluations === []) {
            return new Collection;
        }

        return $this->finales(EvaluationSubmission::query()
            ->where('institution_id', $etablissement)
            ->whereIn('evaluation_id', $evaluations));
    }

    /**
     * La note ne peut-elle plus changer ?
     *
     * `corrige` : l'enseignant a tranché. `soumis` sans question à correction manuelle :
     * l'auto-correction est le dernier mot. `soumis` AVEC une dissertation non notée : la note
     * est DÉFLATÉE (la dissertation compte 0 au dénominateur tant qu'elle n'est pas corrigée),
     * donc la compter annoncerait un échec qui n'a pas eu lieu.
     *
     * C'est le critère que la synchro KLASSCI applique déjà pour refuser de pousser (409).
     */
    public function estFinale(EvaluationSubmission $copie): bool
    {
        if ($copie->status === 'corrige') {
            return true;
        }

        $evaluation = $copie->evaluation;

        return $evaluation instanceof Evaluation
            && ! $this->grading->evaluationRequiresManualGrading($evaluation);
    }

    /**
     * @param  Builder<EvaluationSubmission>  $copies
     * @return Collection<int, EvaluationSubmission>
     */
    private function finales(Builder $copies): Collection
    {
        $remises = self::comptables($copies, ['soumis', 'corrige'])->get();

        return $remises->filter(fn (EvaluationSubmission $copie): bool => $this->estFinale($copie))->values();
    }

    /**
     * #1698 : les copies de ces évaluations qui n'ont PAS de note finale, et qui le disent : les
     * remises dont la note attend une correction manuelle, et les verrouillées (remise refusée
     * après l'échéance, ou tentative ouverte échue, qui se LIT `verrouille`). Mêmes bornes que
     * les copies finales : cet établissement, hors entraînement, évaluation publiée.
     *
     * @param  list<int>  $evaluations
     * @return Collection<int, EvaluationSubmission>
     */
    public function sansNoteFinale(array $evaluations, int $etablissement): Collection
    {
        if ($evaluations === []) {
            return new Collection;
        }

        $copies = self::comptables(EvaluationSubmission::query()
            ->where('institution_id', $etablissement)
            ->whereIn('evaluation_id', $evaluations), ['soumis', 'en_cours', 'verrouille'])->get();

        return $copies->filter(fn (EvaluationSubmission $copie): bool => $copie->status === 'verrouille'
            || ($copie->status === 'soumis' && ! $this->estFinale($copie)))->values();
    }

    /**
     * #1698 (C2) : les dispenses de ces évaluations, dans cet établissement : le carnet en tire
     * l'état `dispense` ; les copies dispensées, elles, sont déjà hors des copies qui comptent.
     *
     * @param  list<int>  $evaluations
     * @return Collection<int, DispenseDEvaluation>
     */
    public function dispensesDesEvaluations(array $evaluations, int $etablissement): Collection
    {
        if ($evaluations === []) {
            return new Collection;
        }

        return DispenseDEvaluation::query()->withoutGlobalScope('institution')
            ->where('institution_id', $etablissement)
            ->whereIn('evaluation_id', $evaluations)
            ->get();
    }

    /**
     * Les copies qui peuvent porter une note de l'élève : de ces statuts STOCKÉS, hors
     * entraînement (`feedback` préfixé `[PRACTICE]`), sur une évaluation publiée, et hors
     * dispense ({@see self::nonDispensees()}). Les plus récentes d'abord.
     *
     * @param  Builder<EvaluationSubmission>  $copies
     * @param  list<string>  $statuts
     * @return Builder<EvaluationSubmission>
     */
    private static function comptables(Builder $copies, array $statuts): Builder
    {
        return $copies
            ->whereIn('status', $statuts)
            ->where(function ($q) {
                $q->whereNull('feedback')
                    ->orWhere('feedback', 'NOT LIKE', '[PRACTICE]%');
            })
            // `questions` est chargée ici : `estFinale()` l'interroge pour chaque copie, et
            // sans ce préchargement chaque note coûterait une requête de plus.
            ->with(['evaluation' => fn ($query) => $query->where('is_published', true)->with('questions')])
            ->whereRelation('evaluation', 'is_published', true)
            // FALSIFICATION : la copie dispensée compte encore.
            ->orderBy('submitted_at', 'desc');
    }

    /**
     * #1698 (C2) : une copie d'un élève DISPENSÉ de son évaluation ne compte nulle part : ni dans
     * Mes notes, ni au tableau de bord, ni dans le bilan, le rang ou le carnet. La dispense désigne
     * l'élève comme ses copies : par son compte, ou par son identité KLASSCI ; dans le même
     * établissement que la copie.
     *
     * @param  Builder<EvaluationSubmission>  $copies
     */
    private static function nonDispensees(Builder $copies): void
    {
        $copie = static fn (string $colonne): string => $copies->qualifyColumn($colonne);

        $copies->whereNotExists(static fn (QueryBuilder $dispense) => $dispense->selectRaw('1')
            ->from('dispenses_d_evaluation')
            ->whereColumn('dispenses_d_evaluation.evaluation_id', $copie('evaluation_id'))
            ->whereColumn('dispenses_d_evaluation.institution_id', $copie('institution_id'))
            ->where(static fn (QueryBuilder $eleve) => $eleve
                ->whereColumn('dispenses_d_evaluation.student_id', $copie('student_id'))
                ->orWhereColumn('dispenses_d_evaluation.klassci_etudiant_id', $copie('klassci_etudiant_id'))));
    }
}
