<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\KlassciProxyService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Logique de démarrage d'une tentative d'évaluation et lecture de la
 * fenêtre temporelle KLASSCI — extrait de `EvaluationStudentController`
 * (startEvaluation + getTimeStatus) lors du split §5.
 *
 * Toutes les vérifications business (publication, questions, fenêtre,
 * tentatives max, mode entraînement) sont centralisées ici. Le caller
 * map les `status` en codes HTTP.
 */
final class EvaluationAttemptStateService
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly KlassciProxyService $klassciService,
    ) {}

    /**
     * Tente de démarrer (ou reprendre) une soumission pour l'étudiant.
     *
     * Statuts possibles :
     *   - ok                 → tentative démarrée / reprise (200 ou 201)
     *   - not_found          → évaluation absente ou non publiée (404)
     *   - no_questions       → évaluation sans question (422)
     *   - no_token           → user sans klassci_token (401)
     *   - window_closed      → fenêtre temporelle fermée hors mode entraînement (403)
     *   - max_attempts       → quota de tentatives atteint hors entraînement (403)
     *
     * @return array{status: string, submission?: EvaluationSubmission, window?: ?array<string, mixed>, message?: string, is_practice?: bool, resumed?: bool}
     */
    public function startAttempt(int $evaluationId, User $user): array
    {
        $evaluation = Evaluation::find($evaluationId);
        if (!$evaluation || !$evaluation->is_published) {
            return ['status' => 'not_found'];
        }

        if ($evaluation->questions()->count() === 0) {
            return ['status' => 'no_questions'];
        }

        $klassciToken = $user->klassci_token;
        if (!$klassciToken) {
            return ['status' => 'no_token'];
        }

        $klassciEtudiantId = $user->klassci_id;
        $isPracticeMode = $evaluation->isTerminee();
        $window = $this->fetchWindowSafe($klassciToken, $evaluation->klassci_evaluation_id);

        $windowError = $this->checkWindow($window, $isPracticeMode, $evaluationId, $klassciEtudiantId);
        if ($windowError !== null) {
            return $windowError;
        }

        $activeSubmission = EvaluationSubmission::where('evaluation_id', $evaluationId)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->where('status', 'en_cours')
            ->first();

        if ($activeSubmission) {
            return [
                'status' => 'ok',
                'submission' => $activeSubmission,
                'window' => $window,
                'resumed' => true,
                'is_practice' => $isPracticeMode,
            ];
        }

        $attemptsCount = EvaluationSubmission::where('evaluation_id', $evaluationId)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->whereIn('status', ['soumis', 'corrige'])
            ->count();

        if (!$isPracticeMode && $evaluation->max_attempts && $attemptsCount >= $evaluation->max_attempts) {
            return [
                'status' => 'max_attempts',
                'message' => 'Nombre maximum de tentatives atteint (' . $evaluation->max_attempts . ')',
            ];
        }

        $submission = EvaluationSubmission::create([
            'evaluation_id' => $evaluationId,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'attempt' => $attemptsCount + 1,
            'status' => 'en_cours',
            'started_at' => now(),
            // Scope tenant explicite herite de l'evaluation (fix E2E #211).
            'institution_id' => $evaluation->institution_id,
            'feedback' => $isPracticeMode ? '[PRACTICE] Entraînement - note non officielle' : null,
        ]);

        return [
            'status' => 'ok',
            'submission' => $submission,
            'window' => $window,
            'resumed' => false,
            'is_practice' => $isPracticeMode,
        ];
    }

    /**
     * @return array{window: ?array<string, mixed>, server_time: string}
     * @throws RuntimeException  Si évaluation absente ou pas de klassci_token
     */
    public function getTimeStatus(int $evaluationId, User $user): array
    {
        $evaluation = Evaluation::find($evaluationId);
        if (!$evaluation) {
            throw new RuntimeException('Évaluation non trouvée');
        }

        $klassciToken = $user->klassci_token;
        if (!$klassciToken) {
            throw new RuntimeException('Token KLASSCI non trouvé');
        }

        $window = $this->fetchWindowSafe($klassciToken, $evaluation->klassci_evaluation_id);
        return [
            'window' => $window,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * @return ?array<string, mixed>  null si pas de fenêtre ou erreur réseau
     */
    private function fetchWindowSafe(string $klassciToken, ?int $klassciEvaluationId): ?array
    {
        try {
            $response = $this->klassciService->requestWithUserToken($klassciToken, 'evaluations', 'GET');
            $klassciEval = collect($response['data'] ?? [])->firstWhere('id', $klassciEvaluationId);
            return $klassciEval['programmation']['window'] ?? null;
        } catch (\Exception $e) {
            $this->logger->warning('Window check failed (KLASSCI)', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * @param  ?array<string, mixed>  $window
     * @return ?array{status: string, window?: ?array<string, mixed>, message?: string}
     */
    private function checkWindow(?array $window, bool $isPracticeMode, int $evaluationId, ?int $klassciEtudiantId): ?array
    {
        if (!$window || $window['is_open'] || $isPracticeMode) {
            return null;
        }

        $message = "L'évaluation n'est pas encore ouverte";
        if (!($window['has_started'] ?? true)) {
            $startAt = \Carbon\Carbon::parse($window['start_at'])->format('d/m/Y à H:i');
            $message = "L'évaluation ouvrira le {$startAt}";
        } elseif ($window['has_ended'] ?? false) {
            $endAt = \Carbon\Carbon::parse($window['end_at'])->format('d/m/Y à H:i');
            $message = "L'évaluation est fermée depuis le {$endAt}";
        }

        $this->logger->warning('Tentative de démarrage hors fenêtre', [
            'evaluation_id' => $evaluationId,
            'student_id' => $klassciEtudiantId,
            'window' => $window,
        ]);

        return ['status' => 'window_closed', 'window' => $window, 'message' => $message];
    }
}
