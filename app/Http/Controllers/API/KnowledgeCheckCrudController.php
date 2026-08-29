<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\StoreKnowledgeCheckRequest;
use App\Http\Requests\UpdateKnowledgeCheckRequest;
use App\Http\Resources\KnowledgeCheckResource;
use App\Services\KnowledgeCheck\KnowledgeCheckCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Controller CRUD des quiz « Testez vos connaissances » (KnowledgeCheck) —
 * extrait de `KnowledgeCheckController` (447l) lors du split SRP.
 * Logique dans {@see KnowledgeCheckCrudService} (DI strict).
 */
final class KnowledgeCheckCrudController extends AuthenticatedController
{
    public function __construct(private readonly KnowledgeCheckCrudService $service)
    {
    }

    /** GET /api/knowledge-checks?chapter_id=... — liste des quiz d'un chapitre. */
    public function index(Request $request): JsonResponse
    {
        $chapterId = $request->query('chapter_id');

        if (! $chapterId) {
            return $this->errorResponse('chapter_id requis', 422);
        }

        $userId = $this->authenticatedUser($request)->id;
        $quizzes = $this->service->listForChapter((int) $chapterId, $userId);

        // Resource : masque les réponses correctes pour les non-staff (sécurité).
        return $this->successResponse(KnowledgeCheckResource::collection($quizzes)->resolve($request));
    }

    /** POST /api/knowledge-checks — créer un quiz (admin / enseignant propriétaire). */
    public function store(StoreKnowledgeCheckRequest $request): JsonResponse
    {
        try {
            $quiz = $this->service->create($request->validated(), $this->authenticatedUser($request));
        } catch (\DomainException $e) {
            return $this->errorResponse('Non autorise a modifier ce chapitre', 403);
        }

        return $this->successResponse($quiz, 'Quiz cree avec succes', 201);
    }

    /** GET /api/knowledge-checks/chapter/{chapterId} — quiz actif d'un chapitre. */
    public function getByChapter(Request $request, int $chapterId): JsonResponse
    {
        $userId = $this->authenticatedUser($request)->id;
        $quiz = $this->service->findActiveForChapter($chapterId, $userId);

        if ($quiz === null) {
            return $this->errorResponse('Aucun quiz actif pour ce chapitre', 404);
        }

        return $this->successResponse((new KnowledgeCheckResource($quiz))->resolve($request));
    }

    /** GET /api/knowledge-checks/{id} — détail d'un quiz. */
    public function show(Request $request, string $id): JsonResponse
    {
        $userId = $this->authenticatedUser($request)->id;
        $quiz = $this->service->findById($id, $userId);

        return $this->successResponse((new KnowledgeCheckResource($quiz))->resolve($request));
    }

    /** PUT /api/knowledge-checks/{id} — update un quiz (admin / enseignant propriétaire). */
    public function update(UpdateKnowledgeCheckRequest $request, string $id): JsonResponse
    {
        try {
            $quiz = $this->service->update($id, $request->validated(), $this->authenticatedUser($request));
        } catch (\DomainException $e) {
            return $this->errorResponse('Non autorise a modifier ce quiz', 403);
        }

        return $this->successResponse($quiz, 'Quiz mis a jour');
    }

    /** DELETE /api/knowledge-checks/{id} — supprimer un quiz (admin / enseignant propriétaire). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        try {
            $this->service->delete($id, $this->authenticatedUser($request));
        } catch (\DomainException $e) {
            return $this->errorResponse('Non autorise a supprimer ce quiz', 403);
        }

        return $this->successResponse(null, 'Quiz supprime');
    }
}
