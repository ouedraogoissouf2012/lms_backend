<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Quiz;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteQuizRequest;
use App\Http\Requests\PublishQuizRequest;
use App\Http\Requests\StoreQuizRequest;
use App\Http\Requests\UpdateQuizRequest;
use App\Models\Quiz;
use App\Services\Quiz\QuizCrudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller — CRUD quiz (split-27/quiz-crud).
 *
 * Split du god-controller `QuizController` historique et de l'intermédiaire
 * `QuizCrudController` (218l) en thin shell + service SRP.
 * Cf. PRODUCTION_STANDARDS.md §5 (controllers ≤200l) et §1.1 (services ≤300l).
 *
 * Toute la logique (filtres, tris, chargements, règles de visibilité,
 * validation publication) vit dans {@see QuizCrudService}. Ce controller
 * se borne à :
 *   1. extraire l'utilisateur authentifié,
 *   2. déléguer au service,
 *   3. transcrire le résultat en `JsonResponse`.
 *
 * Authorization (tenant + ownership) est portée par les `FormRequest::authorize`.
 *
 * @see app/Services/Quiz/QuizCrudService.php
 */
final class QuizCrudController extends AuthenticatedController
{
    public function __construct(private readonly QuizCrudService $crud)
    {
    }

    /**
     * GET /api/quizzes — Liste des quiz (avec filtres).
     */
    public function index(Request $request): JsonResponse
    {
        $user      = $this->authenticatedUser($request);
        $paginator = $this->crud->list($user, $request->all());

        return response()->json([
            'success' => true,
            'data'    => $paginator,
        ]);
    }

    /**
     * POST /api/quizzes — Créer un quiz (enseignants uniquement).
     */
    public function store(StoreQuizRequest $request): JsonResponse
    {
        $quiz = $this->crud->create(
            $request->validated(),
            $this->authenticatedUser($request),
        );

        return response()->json([
            'success' => true,
            'message' => 'Quiz créé avec succès',
            'data'    => $quiz,
        ], 201);
    }

    /**
     * GET /api/quizzes/{quiz} — Détails d'un quiz.
     */
    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $result = $this->crud->show($quiz, $this->authenticatedUser($request));

        $payload = ['success' => $result['success']];
        if ($result['message'] !== null) {
            $payload['message'] = $result['message'];
        }
        if ($result['data'] !== null) {
            $payload['data'] = $result['data'];
        }

        return response()->json($payload, $result['status']);
    }

    /**
     * PUT /api/quizzes/{quiz} — Mettre à jour un quiz.
     */
    public function update(UpdateQuizRequest $request, Quiz $quiz): JsonResponse
    {
        $fresh = $this->crud->update($quiz, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Quiz mis à jour',
            'data'    => $fresh,
        ]);
    }

    /**
     * DELETE /api/quizzes/{quiz} — Supprimer un quiz.
     */
    public function destroy(DeleteQuizRequest $request, Quiz $quiz): JsonResponse
    {
        $this->crud->delete($quiz);

        return response()->json([
            'success' => true,
            'message' => 'Quiz supprimé',
        ]);
    }

    /**
     * POST /api/quizzes/{quiz}/publish — Publier un quiz.
     */
    public function publish(PublishQuizRequest $request, Quiz $quiz): JsonResponse
    {
        $result = $this->crud->publish($quiz);

        $payload = [
            'success' => $result['success'],
            'message' => $result['message'],
        ];
        if ($result['data'] !== null) {
            $payload['data'] = $result['data'];
        }

        return response()->json($payload, $result['status']);
    }
}
