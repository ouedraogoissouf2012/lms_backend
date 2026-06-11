<?php

declare(strict_types=1);

namespace App\Services\Matiere;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\KlassciProxyService;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * MatiereEvaluationsFetcher — fetches evaluations for a matière and enriches them.
 *
 * Extracted from {@see \App\Http\Controllers\API\LMS\LMSMatieresQueryController::matiereDetails}
 * (legacy lines 235-349).
 *
 * Responsibility:
 *   - Fetches all KLASSCI evaluations and filters by matière id.
 *   - Enriches each KLASSCI evaluation with the matching LMS quiz (if any).
 *   - Appends LMS-only evaluations (`klassci_evaluation_id IS NULL`) for that matière.
 *
 * Performance contract preserved (PERF-03 batch 2):
 *   - One main query + `withCount` for questions/submissions + a single batched
 *     load for the user's latest submission per evaluation.
 *
 * Returns:
 *   - `evaluations_enrichies`: merged KLASSCI + LMS-only list (front-facing).
 *   - `evaluations_raw`: KLASSCI raw collection (kept for stats count).
 *
 * @see PRODUCTION_STANDARDS.md §1.1
 */
final class MatiereEvaluationsFetcher
{
    public function __construct(
        private readonly KlassciProxyService $klassciService,
        private readonly LoggerInterface $logger,
        private readonly \App\Services\Evaluation\EvaluationStateService $evaluationState,
    ) {}

    /**
     * @return array{evaluations_enrichies: array<int, array<string, mixed>>, evaluations_raw_count: int}
     */
    public function fetchEvaluationsForMatiere(
        string $klassciToken,
        int $matiereId,
        User $user,
    ): array {
        $klassciEvaluations = $this->fetchKlassciEvaluationsForMatiere($klassciToken, $matiereId);

        $klassciEnriched = $klassciEvaluations
            ->map(fn (array $eval): array => $this->enrichKlassciEvaluation($eval, $user))
            ->all();

        $lmsOnly = $this->fetchLmsOnlyEvaluations($matiereId, $user);

        return [
            'evaluations_enrichies' => array_merge($klassciEnriched, $lmsOnly),
            'evaluations_raw_count' => $klassciEvaluations->count(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function fetchKlassciEvaluationsForMatiere(string $klassciToken, int $matiereId): Collection
    {
        try {
            $evaluationsResponse = $this->klassciService->requestWithUserToken(
                $klassciToken,
                'evaluations',
                'GET'
            );

            /** @var array<int, array<string, mixed>> $evaluationsData */
            $evaluationsData = $evaluationsResponse['data'] ?? [];

            return collect($evaluationsData)->filter(function (array $eval) use ($matiereId): bool {
                $matiereData = $eval['matiere'] ?? null;

                return is_array($matiereData)
                    && isset($matiereData['id'])
                    && $matiereData['id'] === $matiereId;
            })->values();
        } catch (Throwable $e) {
            $this->logger->warning('Erreur récupération évaluations', [
                'matiere_id' => $matiereId,
                'error' => $e->getMessage(),
            ]);

            /** @var Collection<int, array<string, mixed>> $empty */
            $empty = collect();

            return $empty;
        }
    }

    /**
     * @param  array<string, mixed>  $eval
     * @return array<string, mixed>
     */
    private function enrichKlassciEvaluation(array $eval, User $user): array
    {
        $klassciEvaluationId = $eval['id'] ?? null;

        $quizLMS = null;
        if ($klassciEvaluationId !== null) {
            $quizLMS = Evaluation::where('klassci_evaluation_id', $klassciEvaluationId)->first();
        }

        $evalArray = $eval;
        $evalArray['has_online'] = $quizLMS !== null;

        if ($quizLMS !== null) {
            $evalArray['online_version'] = [
                'id' => $quizLMS->id,
                'status' => $quizLMS->status,
                'is_published' => $quizLMS->is_published,
                'is_locked' => $quizLMS->isLocked(),
                'can_be_edited' => $quizLMS->canBeEdited(),
                'questions_count' => $quizLMS->questions()->count(),
                'submissions_count' => $quizLMS->submissions()->count(),
            ];

            if ($user->klassci_id) {
                $submission = $quizLMS->submissions()
                    ->where('klassci_etudiant_id', $user->klassci_id)
                    ->latest()
                    ->first();
                $evalArray['student_submission'] = $submission;
            } else {
                $evalArray['student_submission'] = null;
            }
        } else {
            $evalArray['online_version'] = null;
            $evalArray['student_submission'] = null;
        }

        return $evalArray;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLmsOnlyEvaluations(int $matiereId, User $user): array
    {
        $evaluationsLMSPuresModels = Evaluation::where('klassci_matiere_id', $matiereId)
            ->whereNull('klassci_evaluation_id')
            ->withCount(['questions', 'submissions'])
            ->get();

        /** @var Collection<int, EvaluationSubmission> $userLatestSubmissions */
        $userLatestSubmissions = collect();

        if ($user->klassci_id && $evaluationsLMSPuresModels->isNotEmpty()) {
            $userLatestSubmissions = EvaluationSubmission::query()
                ->whereIn('evaluation_id', $evaluationsLMSPuresModels->pluck('id'))
                ->where('klassci_etudiant_id', $user->klassci_id)
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
