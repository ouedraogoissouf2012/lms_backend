<?php

declare(strict_types=1);

namespace App\Services\Suivi\Decrochage;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * #1704, règle « remises manquées » : parmi les évaluations ATTENDUES de la classe, celles dont
 * l'élève n'a remis aucune copie.
 *
 * - Attendue : vue des élèves de la classe ({@see EvaluationsDeLaClasse}), passée en ligne (une
 *   évaluation sur papier n'a pas de copie dans le LMS), et TERMINÉE depuis au plus 30 jours selon la
 *   seule règle de fin ({@see Evaluation::finPrevue()}). Une évaluation en cours ne manque pas encore.
 * - Remise : une copie soumise ou corrigée, hors entraînement, la règle des copies synchronisées
 *   (`scopeASynchroniser`). Une copie verrouillée (rendue hors délai, refusée) n'est pas une remise.
 * - La copie est à l'élève selon la règle de `scopeOwnedBy`, appliquée à toute la classe en UNE
 *   requête : `student_id`, ou, pour une copie d'avant `student_id`, l'identité KLASSCI de l'élève.
 */
final class RemisesManquees
{
    public const FENETRE_JOURS = 30;

    /**
     * @param  Builder<Evaluation>  $evaluations
     * @param  Collection<int, User>  $eleves
     * @return array{attendues: int, remises: array<int, int>} par compte, le nombre d'attendues remises
     */
    public function de(Builder $evaluations, Collection $eleves, int $institutionId, CarbonInterface $maintenant): array
    {
        $attendues = $this->attendues($evaluations, $maintenant);
        if ($attendues === [] || $eleves->isEmpty()) {
            return ['attendues' => count($attendues), 'remises' => []];
        }

        return ['attendues' => count($attendues), 'remises' => $this->remises($attendues, $eleves, $institutionId)];
    }

    /**
     * @param  Builder<Evaluation>  $evaluations
     * @return list<int>
     */
    private function attendues(Builder $evaluations, CarbonInterface $maintenant): array
    {
        $depuis = $maintenant->copy()->subDays(self::FENETRE_JOURS);

        // Préfiltre SQL large, la fin exacte est calculée par `finPrevue()` : sans `deadline_at`, la
        // fin est au plus un jour après le début (`duree_minutes` <= 1440, StoreEvaluationRequest).
        return array_values($evaluations
            ->where('is_online', true)
            ->where('date_evaluation', '<=', $maintenant)
            ->where(fn (Builder $q) => $q->where('deadline_at', '>=', $depuis)->orWhere('date_evaluation', '>=', $depuis->copy()->subDay()))
            ->get(['id', 'status', 'date_evaluation', 'deadline_at', 'duree_minutes'])
            ->filter(static function (Evaluation $evaluation) use ($maintenant, $depuis): bool {
                $fin = $evaluation->finPrevue();

                return $fin !== null && $evaluation->estTermineeA($maintenant) && $fin->greaterThanOrEqualTo($depuis);
            })
            ->map(static fn (Evaluation $evaluation): int => (int) $evaluation->id)
            ->all());
    }

    /**
     * @param  list<int>  $attendues
     * @param  Collection<int, User>  $eleves
     * @return array<int, int>
     */
    private function remises(array $attendues, Collection $eleves, int $institutionId): array
    {
        $parKlassci = [];
        foreach ($eleves as $eleve) {
            if (is_numeric($eleve->klassci_id)) {
                $parKlassci[(int) $eleve->klassci_id] = (int) $eleve->id;
            }
        }
        $ids = $eleves->map(static fn (User $eleve): int => (int) $eleve->id)->all();

        $copies = EvaluationSubmission::query()->whereIn('status', ['soumis', 'corrige'])
            ->where('institution_id', $institutionId)
            ->whereIn('evaluation_id', $attendues)
            ->where(fn (Builder $q) => $q->whereIn('student_id', $ids)
                ->when($parKlassci !== [], fn (Builder $k) => $k->orWhere(fn (Builder $davant) => $davant
                    ->whereNull('student_id')->whereIn('klassci_etudiant_id', array_keys($parKlassci)))))
            ->get(['evaluation_id', 'student_id', 'klassci_etudiant_id']);

        $remises = [];
        foreach ($copies as $copie) {
            $compte = $copie->student_id !== null ? (int) $copie->student_id : ($parKlassci[(int) $copie->klassci_etudiant_id] ?? null);
            if ($compte !== null) {
                $remises[$compte][(int) $copie->evaluation_id] = true;
            }
        }

        return array_map(static fn (array $parEvaluation): int => count($parEvaluation), $remises);
    }
}
