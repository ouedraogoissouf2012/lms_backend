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
            return $this->errorResponse('Nombre maximum de tentatives atteint', 400);
        }

        return $this->successResponse($payload);
    }

    /**
     * POST /api/knowledge-checks/{id}/submit
     * Soumettre une tentative + auto-grade.
     *
     * Le quota est appliqué au moment de la soumission (le démarrage ne
     * persiste rien) : `max_attempts` → 400, course concurrente perdue sur
     * l'unique de la table → 409, jamais 500 (#540).
     */
    public function submitAttempt(SubmitKnowledgeCheckAttemptRequest $request, string $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $outcome = $this->service->submitAttempt(
            $id,
            $user,
            $request->input('answers', []),
            (int) $request->input('time_spent_seconds', 0),
        );

        return match ($outcome['status']) {
            'max_attempts' => $this->errorResponse('Nombre maximum de tentatives atteint', 400),
            'conflict' => $this->errorResponse(
                'Une autre soumission de cette tentative vient d\'être enregistrée. Rechargez la page.',
                409,
            ),
            default => $this->respondWithSubmission($outcome['data'] ?? []),
        };
    }

    /**
     * Enveloppe le payload d'une soumission acceptée : le `message` métier est
     * remonté hors des données, comme avant le passage aux statuts (#540) — le
     * contrat JSON du succès reste identique.
     *
     * @param  array<string, mixed>  $data
     */
    private function respondWithSubmission(array $data): JsonResponse
    {
        $message = is_string($data['message'] ?? null) ? $data['message'] : '';
        unset($data['message']);

        return $this->successResponse($data, $message);
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
}
