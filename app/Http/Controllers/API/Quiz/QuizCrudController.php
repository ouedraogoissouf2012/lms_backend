<?php

namespace App\Http\Controllers\API\Quiz;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizAnswer;
use App\Http\Requests\StoreQuizRequest;
use App\Http\Requests\UpdateQuizRequest;
use App\Http\Requests\DeleteQuizRequest;
use App\Http\Requests\PublishQuizRequest;
use App\Http\Requests\StartQuizAttemptRequest;
use App\Http\Requests\SubmitQuizAttemptRequest;
use App\Http\Requests\GradeAttemptRequest;
use App\Http\Requests\SaveQuizProgressRequest;
use App\Http\Requests\ShowAttemptRequest;
use App\Http\Requests\GetQuizAttemptsRequest;
use App\Http\Requests\CheckTimeRemainingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * QuizCrudController — extrait verbatim de QuizController.
 * Refactor du god-controller (618 lignes -> 2 fichiers SRP).
 * Aucun changement comportemental.
 */
class QuizCrudController extends AuthenticatedController
{


    /**
     * GET /api/quizzes
     * Liste des quiz (avec filtres)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Quiz::with(['creator:id,name,email,role', 'matiere:id,libelle', 'classe:id,libelle']);

        $user = $this->authenticatedUser($request);

        // Les étudiants ne voient que les quiz publiés et disponibles
        if ($user->isStudent()) {
            $query->available();
        }

        // Filtres
        if ($request->has('lesson_id')) {
            $query->forLesson($request->lesson_id);
        }

        if ($request->has('matiere_id')) {
            $query->forMatiere($request->matiere_id);
        }

        if ($request->has('classe_id')) {
            $query->forClasse($request->classe_id);
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Tri
        $sortBy = $request->get('sort', 'recent');
        switch ($sortBy) {
            case 'title':
                $query->orderBy('title', 'asc');
                break;
            case 'popular':
                $query->orderBy('attempts_count', 'desc');
                break;
            default: // recent
                $query->orderBy('created_at', 'desc');
        }

        $perPage = $request->get('per_page', 15);
        $quizzes = $query->paginate($perPage);

        // Ajouter les infos de tentative pour chaque quiz
        $quizzes->getCollection()->transform(function ($quiz) use ($user) {
            $quiz->user_attempts_count = $quiz->getAttemptsCountForUser($user->id);
            $quiz->user_can_attempt = $quiz->canUserAttempt($user->id);
            $quiz->user_best_attempt = $quiz->getBestAttemptForUser($user->id);
            return $quiz;
        });

        return response()->json([
            'success' => true,
            'data' => $quizzes,
        ]);
    }

    /**
     * POST /api/quizzes
     * Créer un nouveau quiz (Enseignants uniquement)
     */
    public function store(StoreQuizRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['created_by'] = $this->authenticatedUser($request)->id;

        $quiz = Quiz::create($data);
        $quiz->load('creator', 'matiere', 'classe');

        return response()->json([
            'success' => true,
            'message' => 'Quiz créé avec succès',
            'data' => $quiz,
        ], 201);
    }

    /**
     * GET /api/quizzes/{quiz}
     * Détails d'un quiz
     */
    public function show(Request $request, Quiz $quiz): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $quiz->load([
            'creator:id,name,email,role',
            'matiere:id,libelle',
            'classe:id,libelle',
            'lesson:id,title',
        ]);

        // Les étudiants ne peuvent voir que les quiz publiés
        if ($user->isStudent() && !$quiz->isAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce quiz n\'est pas disponible',
            ], 403);
        }

        // Charger les questions (sans les réponses correctes pour les étudiants avant tentative)
        if ($user->isTeacher() || $user->isAdmin()) {
            $quiz->load('questions.answers');
        } else {
            // Pour les étudiants, charger les questions sans révéler les bonnes réponses
            $quiz->load(['questions' => function ($query) {
                $query->with(['answers' => function ($q) {
                    $q->select('id', 'question_id', 'answer_text', 'order');
                }]);
            }]);
        }

        // Infos utilisateur
        $quiz->user_attempts_count = $quiz->getAttemptsCountForUser($user->id);
        $quiz->user_can_attempt = $quiz->canUserAttempt($user->id);
        $quiz->user_best_attempt = $quiz->getBestAttemptForUser($user->id);
        $quiz->user_latest_attempt = $quiz->getLatestAttemptForUser($user->id);

        return response()->json([
            'success' => true,
            'data' => $quiz,
        ]);
    }

    /**
     * PUT /api/quizzes/{quiz}
     * Mettre à jour un quiz
     */
    public function update(UpdateQuizRequest $request, Quiz $quiz): JsonResponse
    {
        $quiz->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Quiz mis à jour',
            'data' => $quiz->fresh(['creator', 'matiere', 'classe']),
        ]);
    }

    /**
     * DELETE /api/quizzes/{quiz}
     * Supprimer un quiz
     */
    public function destroy(DeleteQuizRequest $request, Quiz $quiz): JsonResponse
    {
        $quiz->delete();

        return response()->json([
            'success' => true,
            'message' => 'Quiz supprimé',
        ]);
    }

    /**
     * POST /api/quizzes/{quiz}/publish
     * Publier un quiz
     */
    public function publish(PublishQuizRequest $request, Quiz $quiz): JsonResponse
    {
        // Vérifier qu'il y a au moins une question
        if ($quiz->questions()->count() === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de publier un quiz sans questions',
            ], 422);
        }

        $quiz->publish();

        return response()->json([
            'success' => true,
            'message' => 'Quiz publié',
            'data' => $quiz,
        ]);
    }
}
