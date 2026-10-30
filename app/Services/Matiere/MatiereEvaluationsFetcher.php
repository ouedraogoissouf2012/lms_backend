<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\Evaluation\Student\LearnerEvaluationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * MatiereEvaluationsFetcher — filters and enriches the evaluations of a matière.
 *
 * Extracted from {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::matiereDetails}
 * (legacy lines 235-349).
 *
 * Responsibility:
 *   - Filters the matière's own evaluations out of the KLASSCI payload already
 *     fetched by `MatiereInfoFetcher` (`matieres/{id}`).
 *   - Enriches each KLASSCI evaluation with the matching LMS quiz (if any).
 *   - Appends LMS-only evaluations (`klassci_evaluation_id IS NULL`) for that matière.
 *
 * §1.4 PRODUCTION_STANDARDS.md (Zero N+1 HTTP) — this class used to make its OWN
 * `GET evaluations` call (the entire catalogue, filtered here in PHP) on every
 * matière-details request, duplicating data `MatiereInfoFetcher` had ALREADY
 * fetched via `matieres/{id}` in the very same request. Verified live on 3 real
 * matières before this change: identical ids, identical count, in both sources —
 * `matieres/{id}`'s embedded `evaluations` carries the richer shape (window,
 * lms_integration), matching the precedent already used elsewhere in this codebase
 * (`MyMatieresQueryService::fetchEvaluationsFor()`). This class makes no
 * `GET evaluations` call as a result.
 *
 * Reach: a learner only gets the LMS evaluations of HIS list
 * ({@see LearnerEvaluationScope}, the rule of his list and of the take screen),
 * resolved ONCE per request. Otherwise this sheet would show him evaluations
 * that the take screen then refuses. Staff reach is unchanged.
 *
 * Performance contract preserved (PERF-03 batch 2):
 *   - One main query + `withCount` for questions/submissions + a single batched
 *     load for the user's latest submission per evaluation.
 *
 * Returns:
 *   - `evaluations_enrichies`: merged KLASSCI + LMS-only list (front-facing).
 *   - `evaluations_raw_count`: KLASSCI raw collection count (kept for stats).
 *
 * @see PRODUCTION_STANDARDS.md §1.1, §1.4
 */
final class MatiereEvaluationsFetcher
{
    public function __construct(
        private readonly \App\Services\Evaluation\EvaluationStateService $evaluationState,
        private readonly LearnerEvaluationScope $learnerScope,
    ) {}

    /**
     * @param  array<string, mixed>  $matiereData  Payload `matieres/{id}` deja
     *   recupere par {@see MatiereInfoFetcher} pour CETTE meme requete.
     * @return array{evaluations_enrichies: array<int, array<string, mixed>>, evaluations_raw_count: int}
     */
    public function fetchEvaluationsForMatiere(
        array $matiereData,
        int $matiereId,
        User $user,
    ): array {
        $klassciEvaluations = $this->filterEmbeddedEvaluations($matiereData, $matiereId);
        $reachable = $this->reachableBy($user);

        $klassciEnriched = $klassciEvaluations
            ->map(fn (array $eval): array => $this->enrichKlassciEvaluation($eval, $user, $reachable))
            ->all();

        $lmsOnly = $this->fetchLmsOnlyEvaluations($matiereId, $user, $reachable);

        return [
            'evaluations_enrichies' => array_merge($klassciEnriched, $lmsOnly),
            'evaluations_raw_count' => $klassciEvaluations->count(),
        ];
    }

    /**
     * The LMS evaluations this user reaches on this sheet. A learner: those of
     * his own list, and none without a class. Staff: all of them, as before.
     *
     * @return Builder<Evaluation>
     */
    private function reachableBy(User $user): Builder
    {
        if (! $user->isLearnerAudience()) {
            return Evaluation::query();
        }

        return $this->learnerScope->visibleFor($user) ?? Evaluation::query()->whereRaw('1 = 0');
    }

    /**
     * Les evaluations embarquees sont DEJA scopees par KLASSCI a la matiere
     * demandee : elles sont servies sous `matieres/{id}`, et n'ont donc PAS de
     * cle `matiere` (redondante a cet endroit — verifie sur la reponse reelle :
     * `id, titre, description, type, status, classe, programmation, publication`).
     *
     * Le catalogue global `GET evaluations`, lui, portait bien cette cle, et le
     * filtre `matiere.id === $matiereId` y etait indispensable. Le transposer tel
     * quel ici rejetait les 9 evaluations sur 9 (`matiere` toujours absente) —
     * l'ecran affichait « 0 Evaluations » sur une matiere qui en a 9.
     *
     * On ne re-filtre donc QUE si la cle est presente (defense contre un futur
     * changement de contrat cote KLASSCI qui melangerait plusieurs matieres),
     * sans jamais exclure une evaluation qui ne la porte pas.
     *
     * @param  array<string, mixed>  $matiereData
     * @return Collection<int, array<string, mixed>>
     */
    private function filterEmbeddedEvaluations(array $matiereData, int $matiereId): Collection
    {
        /** @var array<int, array<string, mixed>> $evaluationsData */
        $evaluationsData = is_array($matiereData['evaluations'] ?? null) ? $matiereData['evaluations'] : [];

        return collect($evaluationsData)->filter(function (array $eval) use ($matiereId): bool {
            $matiere = $eval['matiere'] ?? null;

            if (!is_array($matiere)) {
                return true;
            }

            $id = $matiere['id'] ?? null;

            // KLASSCI livre les ids tantot en entier, tantot en chaine selon
            // l'endpoint : on normalise, sans elargir le type pour faire taire
            // PHPStan (une valeur d'un autre type reste non filtrable → conservee).
            if (!is_int($id) && !is_string($id)) {
                return true;
            }

            return (int) $id === $matiereId;
        })->values();
    }

    /**
     * @param  array<string, mixed>  $eval
     * @param  Builder<Evaluation>  $reachable
     * @return array<string, mixed>
     */
    private function enrichKlassciEvaluation(array $eval, User $user, Builder $reachable): array
    {
        $klassciEvaluationId = $eval['id'] ?? null;

        // A version this user cannot reach counts as no version: the sheet never
        // links to a take screen that would refuse it.
        $quizLMS = null;
        if ($klassciEvaluationId !== null) {
            $quizLMS = (clone $reachable)->where('klassci_evaluation_id', $klassciEvaluationId)->first();
        }

        $evalArray = $eval;
        $evalArray['has_online'] = $quizLMS !== null;

        if ($quizLMS !== null) {
            $evalArray['online_version'] = [
                'id' => $quizLMS->id,
                'status' => $quizLMS->status,
                'is_published' => $quizLMS->is_published,
                'is_locked' => $this->evaluationState->isLocked($quizLMS),
                'can_be_edited' => $this->evaluationState->canBeEdited($quizLMS),
                'questions_count' => $quizLMS->questions()->count(),
                'submissions_count' => $quizLMS->submissions()->count(),
            ];

            // Sa copie, par l'UNIQUE définition de la propriété d'une copie
            // (sans identité KLASSCI ou sans établissement : aucune).
            $evalArray['student_submission'] = $quizLMS->submissions()->ownedBy($user)->latest()->first();
        } else {
            $evalArray['online_version'] = null;
            $evalArray['student_submission'] = null;
        }

        return $evalArray;
    }

    /**
     * @param  Builder<Evaluation>  $reachable
     * @return array<int, array<string, mixed>>
     */
    private function fetchLmsOnlyEvaluations(int $matiereId, User $user, Builder $reachable): array
    {
        $evaluationsLMSPuresModels = (clone $reachable)->where('klassci_matiere_id', $matiereId)
            ->whereNull('klassci_evaluation_id')
            ->withCount(['questions', 'submissions'])
            ->get();

        /** @var Collection<int, EvaluationSubmission> $userLatestSubmissions */
        $userLatestSubmissions = collect();

        if ($user->klassci_id && $evaluationsLMSPuresModels->isNotEmpty()) {
            $userLatestSubmissions = EvaluationSubmission::query()
                ->whereIn('evaluation_id', $evaluationsLMSPuresModels->pluck('id'))
                ->ownedBy($user)
                ->orderByDesc('id')
                ->get()
                ->groupBy('evaluation_id')
                ->map(fn ($subs) => $subs->first()); // latest per eval
        }

        return $evaluationsLMSPuresModels->map(function (Evaluation $eval) use ($userLatestSubmissions): array {
            return [
                'id' => 'lms_' . $eval->id,
                'lms_id' => $eval->id,
                'titre' => $eval->titre,
                'description' => $eval->description,
                'type' => 'lms_pure',
                'matiere' => null,
                'classe' => null,
                'programmation' => [
                    'date_evaluation' => $eval->date_evaluation,
                    'duree_minutes' => $eval->duree_minutes,
                    'coefficient' => $eval->coefficient,
                    'bareme' => $eval->bareme,
                ],
                'has_online' => true,
                'online_version' => [
                    'id' => $eval->id,
                    'status' => $eval->status,
                    'is_published' => $eval->is_published,
                    'is_locked' => $this->evaluationState->isLocked($eval),
                    'can_be_edited' => $this->evaluationState->canBeEdited($eval),
                    'questions_count' => $eval->questions_count,
                    'submissions_count' => $eval->submissions_count,
                ],
                'student_submission' => $userLatestSubmissions->get($eval->id),
            ];
        })->all();
    }
}
