<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationGradingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

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
     * @return Collection<int, EvaluationSubmission>
     */
    public function deLEleve(User $eleve): Collection
    {
        return $this->finales(EvaluationSubmission::query()->ownedBy($eleve));
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
        $remises = $copies
            ->whereIn('status', ['soumis', 'corrige'])
            ->where(function ($q) {
                $q->whereNull('feedback')
                    ->orWhere('feedback', 'NOT LIKE', '[PRACTICE]%');
            })
            // `questions` est chargée ici : `estFinale()` l'interroge pour chaque copie, et
            // sans ce préchargement chaque note coûterait une requête de plus.
            ->with(['evaluation' => fn ($query) => $query->where('is_published', true)->with('questions')])
            ->whereRelation('evaluation', 'is_published', true)
            ->orderBy('submitted_at', 'desc')
            ->get();

        return $remises->filter(fn (EvaluationSubmission $copie): bool => $this->estFinale($copie))->values();
    }
}
