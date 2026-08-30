<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;

/**
 * Liste enrichie des évaluations d'un étudiant — extrait de
 * `EvaluationStudentController::studentEvaluationsForUser` (split §5).
 *
 * Enrichit chaque évaluation LMS avec : fenêtre temporelle KLASSCI,
 * lms_integration, classe, matière, questions_count, dernière soumission
 * de l'étudiant.
 *
 * Returns `null` quand aucune classe n'est résolue pour l'étudiant
 * (caller render 404). Throws `MissingKlassciTokenException` si pas de klassci_token
 * (caller render 401).
 */
final class StudentEvaluationsListService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * @return array<int, array<string, mixed>>|null  null = classe non trouvée
     * @throws MissingKlassciTokenException  Si pas de klassci_token sur le user
     */
    public function getEnrichedEvaluationsForStudent(User $user): ?array
    {
        $klassciToken = $user->klassci_token;
        if (!$klassciToken) {
            throw MissingKlassciTokenException::forUser($user->id);
        }

        $klassciEtudiantId = $user->klassci_id;

        $this->logger->info('Student Evaluations request', [
            'user_id' => $user->id,
            'klassci_id' => $klassciEtudiantId,
        ]);

        $dashboard = $this->klassciService->requestWithUserToken($klassciToken, 'me/dashboard', 'GET');
        $classeId = $dashboard['data']['classe']['id'] ?? null;
        if (!$classeId) {
            return null;
        }

        $evaluationsLMS = Evaluation::with('questions', 'submissions')
            ->where('klassci_classe_id', $classeId)
            ->where('is_published', true)
            ->whereIn('status', ['planifiee', 'en_cours', 'terminee'])
            ->whereHas('questions')
            ->orderBy('date_evaluation', 'desc')
            ->get();

        $klassciEvaluations = $this->fetchKlassciEvaluationsSafe($klassciToken);

        return $evaluationsLMS->map(
            fn ($evalLMS) => $this->enrichEvaluation($evalLMS, $klassciEvaluations, $klassciEtudiantId, $klassciToken)
        )->values()->toArray();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function fetchKlassciEvaluationsSafe(string $klassciToken): \Illuminate\Support\Collection
    {
        try {
            $response = $this->klassciService->requestWithUserToken($klassciToken, 'evaluations', 'GET');
            return collect($response['data'] ?? []);
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch KLASSCI evaluations for windows', ['error' => $e->getMessage()]);
            return collect([]);
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array<string, mixed>>  $klassciEvaluations
     * @return array<string, mixed>
     */
    private function enrichEvaluation(
        Evaluation $evalLMS,
        \Illuminate\Support\Collection $klassciEvaluations,
        ?int $klassciEtudiantId,
        string $klassciToken
    ): array {
        $evalArray = $evalLMS->toArray();
        $klassciEval = $klassciEvaluations->firstWhere('id', $evalLMS->klassci_evaluation_id);

        if ($klassciEval) {
            $evalArray['programmation'] = $klassciEval['programmation'] ?? null;
            if ($evalArray['programmation']) {
                $evalArray['programmation']['date_evaluation'] = $evalLMS->date_evaluation;
            }
            $evalArray['lms_integration'] = $klassciEval['lms_integration'] ?? null;
            $evalArray['classe'] = $klassciEval['classe'] ?? null;
            $evalArray['matiere'] = $klassciEval['matiere'] ?? null;
        } else {
            $this->enrichPureLmsEvaluation($evalArray, $evalLMS, $klassciToken);
        }

        $evalArray['questions_count'] = $evalLMS->questions->count();
        $evalArray['student_submission'] = $evalLMS->submissions()
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->latest()
            ->first();

        return $evalArray;
    }

    /**
     * @param  array<string, mixed>  $evalArray  Modifié par référence.
     */
    private function enrichPureLmsEvaluation(array &$evalArray, Evaluation $evalLMS, string $klassciToken): void
    {
        try {
            if ($evalLMS->klassci_matiere_id) {
                $matiereResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "matieres/{$evalLMS->klassci_matiere_id}",
                    'GET'
                );
                $evalArray['matiere'] = $matiereResponse['data']['matiere'] ?? null;
            }

            if ($evalLMS->klassci_classe_id) {
                $classeResponse = $this->klassciService->requestWithUserToken(
                    $klassciToken,
                    "classes/{$evalLMS->klassci_classe_id}",
                    'GET'
                );
                $evalArray['classe'] = $classeResponse['data'] ?? null;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not fetch matiere/classe for pure LMS eval', ['error' => $e->getMessage()]);
        }
    }
}
