<?php

declare(strict_types=1);

namespace App\Services\Evaluation\Student;

use App\Exceptions\MissingKlassciTokenException;
use App\Models\Evaluation;
use App\Models\EvaluationSubmission;
use App\Models\User;
use App\Services\KlassciProxyService;
use App\Services\Seances\KlassciPayload;
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
     *   - window_check_failed → fenêtre non vérifiable (KLASSCI indisponible) hors entraînement (503)
     *   - max_attempts       → quota de tentatives atteint hors entraînement (403)
     *   - retake_forbidden   → allow_retake=false et une tentative déjà consommée (403)
     *   - offline_only       → is_online=false, pas de passage LMS (403)
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

        if (! $isPracticeMode && $evaluation->is_online === false) {
            return [
                'status' => 'offline_only',
                'message' => 'Cette évaluation ne se passe pas en ligne.',
            ];
        }

        [$windowError, $window] = $this->resolveWindowGate(
            $klassciToken,
            $evaluation,
            $isPracticeMode,
            $evaluationId,
            $klassciEtudiantId
        );
        if ($windowError !== null) {
            return $windowError;
        }

        return $this->resolveOrCreateSubmission($evaluation, $klassciEtudiantId, $isPracticeMode, $window);
    }

    /**
     * Reprend la tentative `en_cours` de l'étudiant si elle existe, sinon
     * applique le quota `max_attempts` (hors entraînement) puis crée une nouvelle
     * soumission. Extrait de `startAttempt` pour respecter §1.1 (≤40 lignes).
     *
     * @param  ?array<string, mixed>  $window
     * @return array{status: string, submission?: EvaluationSubmission, window?: ?array<string, mixed>, message?: string, is_practice?: bool, resumed?: bool}
     */
    private function resolveOrCreateSubmission(Evaluation $evaluation, ?int $klassciEtudiantId, bool $isPracticeMode, ?array $window): array
    {
        $activeSubmission = EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->where('status', 'en_cours')
            ->first();

        if ($activeSubmission) {
            return $this->okResponse($activeSubmission, $window, true, $isPracticeMode);
        }

        $attemptsCount = EvaluationSubmission::where('evaluation_id', $evaluation->id)
            ->where('klassci_etudiant_id', $klassciEtudiantId)
            ->whereIn('status', ['soumis', 'corrige'])
            ->count();

        if (! $isPracticeMode && $attemptsCount > 0 && $evaluation->allow_retake === false) {
            return [
                'status' => 'retake_forbidden',
                'message' => 'Les reprises ne sont pas autorisées pour cette évaluation.',
            ];
        }

        if (!$isPracticeMode && $evaluation->max_attempts && $attemptsCount >= $evaluation->max_attempts) {
            return [
                'status' => 'max_attempts',
                'message' => 'Nombre maximum de tentatives atteint (' . $evaluation->max_attempts . ')',
            ];
        }

        $submission = EvaluationSubmission::create([
            'evaluation_id' => $evaluation->id,
            'klassci_etudiant_id' => $klassciEtudiantId,
            'attempt' => $attemptsCount + 1,
            'status' => 'en_cours',
            'started_at' => now(),
            // Scope tenant explicite herite de l'evaluation (fix E2E #211).
            'institution_id' => $evaluation->institution_id,
            'feedback' => $isPracticeMode ? '[PRACTICE] Entraînement - note non officielle' : null,
        ]);

        return $this->okResponse($submission, $window, false, $isPracticeMode);
    }

    /**
     * Réponse `ok` (tentative démarrée ou reprise) — construit le payload commun
     * aux deux chemins (reprise / création) pour éviter la duplication.
     *
     * @param  ?array<string, mixed>  $window
     * @return array{status: string, submission: EvaluationSubmission, window: ?array<string, mixed>, resumed: bool, is_practice: bool}
     */
    private function okResponse(EvaluationSubmission $submission, ?array $window, bool $resumed, bool $isPracticeMode): array
    {
        return [
            'status' => 'ok',
            'submission' => $submission,
            'window' => $window,
            'resumed' => $resumed,
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
            throw MissingKlassciTokenException::forUser($user->id);
        }

        // Endpoint informatif : on tolère `null` (pas de fenêtre OU KLASSCI
        // indisponible) — ce n'est pas un gate (#499, getTimeStatus inchangé).
        $window = $this->fetchWindow($klassciToken, $evaluation->klassci_evaluation_id)['window'];
        return [
            'window' => $window,
            'server_time' => now()->toIso8601String(),
        ];
    }

    /**
     * Récupère la fenêtre temporelle KLASSCI d'une évaluation, en distinguant
     * « appel échoué » de « pas de fenêtre configurée » (#499) :
     *   - fetched=false            → l'appel KLASSCI a échoué (le caller doit
     *                                fail-closed hors mode entraînement) ;
     *   - fetched=true, window=null → appel réussi, aucune fenêtre (toujours ouverte) ;
     *   - fetched=true, window=[…]  → fenêtre à évaluer par {@see checkWindow}.
     *
     * @return array{fetched: bool, window: ?array<string, mixed>}
     */
    private function fetchWindow(string $klassciToken, ?int $klassciEvaluationId): array
    {
        try {
            $response = $this->klassciService->requestWithUserToken($klassciToken, 'evaluations', 'GET');
            $klassciEval = collect($response['data'] ?? [])->firstWhere('id', $klassciEvaluationId);
            $rawWindow = $klassciEval['programmation']['window'] ?? null;

            return [
                'fetched' => true,
                'window' => is_array($rawWindow) ? KlassciPayload::asArray($rawWindow) : null,
            ];
        } catch (\Exception $e) {
            $this->logger->warning('Window check failed (KLASSCI)', ['error' => $e->getMessage()]);

            return ['fetched' => false, 'window' => null];
        }
    }

    /**
     * Résout le « gate » de fenêtre : renvoie `[erreur, null]` si le démarrage
     * doit être bloqué (fenêtre invérifiable hors entraînement, ou fermée),
     * sinon `[null, window]` pour poursuivre. Centralise la logique fenêtre pour
     * garder `startAttempt` sous la limite §1.1.
     *
     * @return array{0: ?array{status: string, window?: ?array<string, mixed>, message?: string}, 1: ?array<string, mixed>}
     */
    private function resolveWindowGate(
        string $klassciToken,
        Evaluation $evaluation,
        bool $isPracticeMode,
        int $evaluationId,
        ?int $klassciEtudiantId
    ): array {
        $windowResult = $this->fetchWindow($klassciToken, $evaluation->klassci_evaluation_id);

        // Fail-closed : fenêtre non vérifiable (KLASSCI indisponible) hors
        // entraînement → refus, aucune tentative créée (#499).
        if (! $windowResult['fetched'] && ! $isPracticeMode) {
            $this->logger->warning('Démarrage refusé : fenêtre non vérifiable (KLASSCI indisponible)', [
                'evaluation_id' => $evaluationId,
                'student_id' => $klassciEtudiantId,
            ]);

            return [[
                'status' => 'window_check_failed',
                'message' => "Impossible de vérifier la disponibilité de l'évaluation pour le moment. Veuillez réessayer dans un instant.",
            ], null];
        }

        $window = $windowResult['window'];

        return [$this->checkWindow($window, $isPracticeMode, $evaluationId, $klassciEtudiantId), $window];
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
