<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Teacher;

use App\Models\Classe;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\EvaluationEnrichmentService;
use App\Support\Logging\CaughtExceptionLog;
use Illuminate\Support\Collection;
use Throwable;

/**
 * École créée depuis le LMS : les résultats d'une évaluation, par élève de sa
 * classe (D72, E3).
 *
 * Le roster est celui des inscriptions ACTIVES de la classe de l'évaluation
 * (`classe_id`) ; l'évaluation est présentée sans catalogue KLASSCI. Aucun
 * jeton n'est exigé, aucun appel ne part vers KLASSCI. La forme de la réponse
 * est celle de la source KLASSCI ({@see ResultatsDeClasse}).
 *
 * ## Un seul roster actif
 *
 * L'élève voit les évaluations de ses classes où son inscription est active
 * (E2a, {@see \App\Services\Evaluation\Student\LocalLearnerEvaluationAudience}) ;
 * l'enseignant voit, dans les résultats, les élèves dont l'inscription à la
 * classe est active. C'est le même pivot (`classe_etudiant.statut = actif`), lu
 * ici par la relation du modèle {@see Classe::etudiantsActifs()} qui sert déjà
 * les effectifs locaux : aucune seconde règle n'est écrite.
 *
 * Un élève n'a qu'un nom (`users.name`) : `etudiant_nom` et
 * `etudiant_nom_complet` le portent, `etudiant_prenom` est vide.
 *
 * ## Le roster, plus les auteurs des copies
 *
 * Aucune copie remise n'est jamais invisible pour l'enseignant : un auteur hors
 * du roster (admis par le repli `user_classes` de l'audience, C24, ou désinscrit
 * après sa remise) a sa ligne, marquée `inscription: null`. La convergence de
 * l'audience et du roster reste C24.
 */
final class LocalEvaluationResultsReader implements EvaluationResultsReader
{
    public function __construct(
        private readonly EvaluationEnrichmentService $presentation,
        private readonly CaughtExceptionLog $journal,
    ) {}

    /**
     * @return array{status:int, payload:array<string, mixed>}
     */
    public function getResultsByClass(int $evaluationId, User $teacher): array
    {
        try {
            // Bornage EXPLICITE : le scope global est fail-open sans tenant. Et
            // `where(col, null)` deviendrait `IS NULL` : sans établissement,
            // rien (revue d'E3).
            $evaluation = $teacher->institution_id === null ? null : Evaluation::with(['questions', 'submissions'])
                ->where('institution_id', $teacher->institution_id)
                ->find($evaluationId);

            if (! $evaluation instanceof Evaluation) {
                return ResultatsDeClasse::introuvable();
            }

            $resultats = $this->lignes($evaluation);

            return ResultatsDeClasse::reponse(
                $this->presentation->enrichWithoutCatalogue(collect([$evaluation]))[0],
                $resultats,
                ResultatsDeClasse::statistiques($resultats, count($resultats)),
            );
        } catch (Throwable $e) {
            $this->journal->error('❌ Erreur récupération résultats évaluation', $e, [
                'evaluation_id' => $evaluationId,
            ]);

            return ResultatsDeClasse::echec();
        }
    }

    /**
     * Une ligne par inscrit actif, puis une par auteur d'une copie hors du
     * roster : aucune copie remise n'est jamais invisible pour l'enseignant.
     *
     * @return list<array<string, mixed>>
     */
    private function lignes(Evaluation $evaluation): array
    {
        $inscrits = $this->inscritsActifs($evaluation);
        $copies = DernieresCopies::de($evaluation);
        $roster = array_values($inscrits->map(static fn (User $eleve): array => ResultatsDeClasse::ligne(
            $eleve->id,
            $eleve->name,
            '',
            (string) $eleve->name,
            $copies->pour($eleve->id, $eleve->klassci_id),
            ResultatsDeClasse::INSCRIT,
        ))->all());
        $horsRoster = $copies->horsRoster(
            array_values($inscrits->map(static fn (User $eleve): array => [$eleve->id, [$eleve->klassci_id]])->all()),
            $evaluation->institution_id,
        );

        return [...$roster, ...ResultatsDeClasse::horsRoster(
            $horsRoster,
            $evaluation->institution_id,
            static fn (EvaluationSubmission $copie): mixed => $copie->student_id,
        )];
    }

    /**
     * Les élèves dont l'inscription à la classe de l'évaluation est active,
     * bornés à l'établissement. Sans classe, personne.
     *
     * @return Collection<int, User>
     */
    private function inscritsActifs(Evaluation $evaluation): Collection
    {
        $classe = $evaluation->classe_id === null ? null : Classe::query()
            ->where('institution_id', $evaluation->institution_id)
            ->find($evaluation->classe_id);

        if (! $classe instanceof Classe) {
            return collect();
        }

        return $classe->etudiantsActifs()
            ->whereIn('users.id', User::query()->where('institution_id', $evaluation->institution_id)->select('id'))
            ->get(['users.id', 'users.name', 'users.klassci_id'])
            ->toBase();
    }
}
