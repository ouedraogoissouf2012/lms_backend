<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\BusinessException;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SubmitKnowledgeCheckAttemptRequest;
use App\Models\KnowledgeCheck;
use App\Models\User;
use App\Services\KnowledgeCheck\KnowledgeCheckAttemptService;
use App\Services\Lesson\LessonProgressGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller des tentatives étudiant pour les quiz « Testez vos
 * connaissances » (KnowledgeCheck).
 *
 * Extrait de `KnowledgeCheckController` (447 lignes → split SRP). Couvre
 * les endpoints « state machine » de l'étudiant : démarrer, soumettre,
 * consulter son historique.
 *
 * Thin controller : la logique métier vit dans
 * {@see KnowledgeCheckAttemptService} (DI strict via constructor).
 */
final class KnowledgeCheckAttemptController extends AuthenticatedController
{
    public function __construct(
        private readonly KnowledgeCheckAttemptService $service,
        private readonly LessonProgressGate $gate,
    ) {
    }

    /**
     * POST /api/knowledge-checks/{id}/start
     * Démarrer une tentative étudiant.
     */
    public function startAttempt(Request $request, string $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($refus = $this->refusTentative($id, $user)) {
            return $refus;
        }

        $payload = $this->service->startAttempt($id, $user);

        if ($payload === null) {
            return $this->errorResponse('Nombre maximum de tentatives atteint', 400);
        }

        return $this->successResponse($payload);
    }

    /**
     * POST /api/knowledge-checks/{id}/submit
     * Soumettre une tentative + auto-grade.
     *
     * Deux refus portent leur motif, sans rien écrire (C44) : un quiz modifié
     * depuis le démarrage (409 `quiz_changed_since_start`), une réponse hors des
     * options affichées (422 `answer_not_an_option_index`, le motif de la forme
     * refusée par la validation, C45).
     */
    public function submitAttempt(SubmitKnowledgeCheckAttemptRequest $request, string $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        if ($refus = $this->refusTentative($id, $user)) {
            return $refus;
        }

        try {
            $result = $this->service->submitAttempt(
                $id,
                $user,
                $request->input('answers', []),
                (int) $request->input('time_spent_seconds', 0),
            );
        } catch (BusinessException $e) {
            $statut = (int) $e->getCode();
            $erreurs = $statut === 422 ? ['answers' => [$e->getMessage()]] : [];

            return $this->errorResponse($e->getMessage(), $statut, $erreurs, $e->reason);
        }

        $message = $result['message'];
        unset($result['message']);

        return $this->successResponse($result, $message);
    }

    /**
     * GET /api/knowledge-checks/{id}/my-attempts
     * Historique des tentatives de l'utilisateur connecté pour ce quiz.
     */
    public function myAttempts(Request $request, string $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return $this->successResponse($this->service->getMyAttempts($id, $user));
    }

    /**
     * Refus de jouer un quiz dont le chapitre n'est pas lisible (#934), AVANT
     * toute écriture : aucune tentative créée, aucun essai consommé. Un quiz
     * inexistant reçoit le MÊME 404 qu'un quiz illisible.
     */
    private function refusTentative(string $id, User $user): ?JsonResponse
    {
        $quiz = KnowledgeCheck::query()->with('chapter')->find($id);
        $refus = $this->gate->refusEcritureQuiz($quiz instanceof KnowledgeCheck ? $quiz : null, $user);

        return $refus === null ? null : $this->errorResponse($refus['message'], $refus['status']);
    }
}
