<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\SubmitKnowledgeCheckAttemptRequest;
use App\Services\KnowledgeCheck\KnowledgeCheckAttemptService;
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
    ) {
    }

    /**
     * POST /api/knowledge-checks/{id}/start
     * Démarrer une tentative étudiant.
     */
    public function startAttempt(Request $request, string $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $payload = $this->service->startAttempt($id, $user);

        if ($payload === null) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre maximum de tentatives atteint',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * POST /api/knowledge-checks/{id}/submit
     * Soumettre une tentative + auto-grade.
     */
    public function submitAttempt(SubmitKnowledgeCheckAttemptRequest $request, string $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->service->submitAttempt(
            $id,
            $user,
            $request->input('answers', []),
            (int) $request->input('time_spent_seconds', 0),
        );

        $message = $result['message'];
        unset($result['message']);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $result,
        ]);
    }

    /**
     * GET /api/knowledge-checks/{id}/my-attempts
     * Historique des tentatives de l'utilisateur connecté pour ce quiz.
     */
    public function myAttempts(Request $request, string $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        return response()->json([
            'success' => true,
            'data' => $this->service->getMyAttempts($id, $user),
        ]);
    }
}
