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
use App\Services\Quiz\QuizGradingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * QuizAttemptController — extrait verbatim de QuizController.
 * Refactor du god-controller (618 lignes -> 2 fichiers SRP).
 *
 * DI du grading service (PERF-04) — la logique métier de correction est
 * extraite des modèles vers `QuizGradingService`. Les modèles conservent
 * des thin wrappers pour préserver les call sites internes (ex. submitAttempt).
 */
class QuizAttemptController extends AuthenticatedController
{
    public function __construct(private QuizGradingService $gradingService)
    {
    }

    /**
     * POST /api/quizzes/{quiz}/start
     * Démarrer une nouvelle tentative de quiz
     */
    public function startAttempt(StartQuizAttemptRequest $request, Quiz $quiz): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // Vérifier que le quiz est disponible
        if (!$quiz->isAvailable()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce quiz n\'est pas disponible actuellement',
            ], 403);
        }

        // Vérifier le nombre de tentatives
        if (!$quiz->canUserAttempt($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous avez atteint le nombre maximum de tentatives',
            ], 403);
        }

        // Créer une nouvelle tentative
        $attemptNumber = $quiz->getAttemptsCountForUser($user->id) + 1;

        $attempt = QuizAttempt::create([
            'quiz_id' => $quiz->id,
            'user_id' => $user->id,
            'attempt_number' => $attemptNumber,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        // Charger les questions (sans les bonnes réponses)
        $questions = $quiz->questions()->with(['answers' => function ($query) {
            $query->select('id', 'question_id', 'answer_text', 'order')->ordered();
        }])->ordered()->get();

        // Mélanger si nécessaire
        if ($quiz->shuffle_questions) {
            $questions = $questions->shuffle();
        }

        if ($quiz->shuffle_answers) {
            $questions->each(function ($question) {
                $question->setRelation('answers', $question->answers->shuffle());
            });
        }

        return response()->json([
            'success' => true,
            'message' => 'Tentative démarrée',
            'data' => [
                'attempt' => $attempt,
                'quiz' => $quiz,
                'questions' => $questions,
                'time_remaining' => $attempt->getTimeRemaining(),
            ],
        ], 201);
    }

    /**
     * POST /api/quiz-attempts/{id}/submit
     * Soumettre les réponses d'une tentative
     */
    public function submitAttempt(SubmitQuizAttemptRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Vérifier les permissions
        $user = $this->authenticatedUser($request);
        if ($attempt->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé',
            ], 403);
        }

        // Vérifier le statut
        if ($attempt->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Cette tentative a déjà été soumise',
            ], 422);
        }

        // NOUVEAU: Vérifier si le temps est écoulé (sécurité côté serveur)
        if ($attempt->isTimeExpired()) {
            // Soumettre automatiquement avec les réponses actuelles ou vides
            $this->gradingService->submitAttempt($attempt, $request->answers ?? []);
            return response()->json([
                'success' => false,
                'message' => 'Le temps est écoulé. Votre tentative a été soumise automatiquement.',
                'data' => [
                    'attempt' => $attempt,
                    'time_expired' => true,
                ],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Soumettre les réponses
        $this->gradingService->submitAttempt($attempt, $request->answers);

        // Charger les données complètes
        $attempt->load(['quiz.questions.answers', 'user:id,name,email']);

        // Préparer les résultats
        $result = [
            'attempt' => $attempt,
            'score' => $attempt->score,
            'points_earned' => $attempt->points_earned,
            'points_possible' => $attempt->points_possible,
            'passed' => $attempt->passed,
            'time_spent' => $attempt->getFormattedTimeSpent(),
        ];

        // Si le quiz permet de voir les réponses correctes
        if ($attempt->quiz->show_correct_answers && $attempt->status === 'graded') {
            $result['questions_with_results'] = $this->getQuestionsWithResults($attempt);
        }

        return response()->json([
            'success' => true,
            'message' => 'Tentative soumise avec succès',
            'data' => $result,
        ]);
    }

    /**
     * GET /api/quiz-attempts/{id}
     * Détails d'une tentative
     */
    public function showAttempt(ShowAttemptRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with(['quiz', 'user:id,name,email'])->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Permissions: tenant + ownership + role enforcement is delegated to
        // ShowAttemptRequest::authorize() (cf. #87 fix). We still need $user
        // here because the visibility check below decides whether to expose
        // corrected questions/answers (feature gate, not access control).
        $user = $this->authenticatedUser($request);

        // Si la tentative est terminée et que le quiz permet la révision
        if ($attempt->status !== 'in_progress' && ($attempt->quiz->allow_review || $user->isTeacher() || $user->isAdmin())) {
            $attempt->questions_with_results = $this->getQuestionsWithResults($attempt);
        }

        $attempt->time_spent_formatted = $attempt->getFormattedTimeSpent();
        $attempt->time_remaining = $attempt->getTimeRemaining();

        return response()->json([
            'success' => true,
            'data' => $attempt,
        ]);
    }

    /**
     * GET /api/quizzes/{quiz}/attempts
     * Liste des tentatives pour un quiz (Enseignants)
     */
    public function getAttempts(GetQuizAttemptsRequest $request, Quiz $quiz): JsonResponse
    {
        // Authorization (tenant + ownership + moderator role) is enforced by
        // GetQuizAttemptsRequest::authorize() (cf. #87 fix).
        $query = $quiz->attempts()->with('user:id,name,email')->submitted();

        // Filtres
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        $query->orderBy('submitted_at', 'desc');

        $perPage = $request->get('per_page', 20);
        $attempts = $query->paginate($perPage);

        // Ajouter formatted time
        $attempts->getCollection()->transform(function ($attempt) {
            $attempt->time_spent_formatted = $attempt->getFormattedTimeSpent();
            return $attempt;
        });

        return response()->json([
            'success' => true,
            'data' => $attempts,
        ]);
    }

    /**
     * POST /api/quiz-attempts/{id}/grade
     * Correction manuelle d'une tentative (Enseignants)
     */
    public function gradeAttempt(GradeAttemptRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        $this->gradingService->manualGradeAttempt(
            $attempt,
            $request->points_earned,
            $this->authenticatedUser($request)->id,
            $request->feedback
        );

        return response()->json([
            'success' => true,
            'message' => 'Tentative notée avec succès',
            'data' => $attempt->fresh(['user', 'grader']),
        ]);
    }

    /**
     * GET /api/quiz-attempts/{id}/time-remaining
     * Vérifier le temps restant pour une tentative (évite les tricheries)
     */
    public function checkTimeRemaining(CheckTimeRemainingRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Vérifier les permissions
        $user = $this->authenticatedUser($request);
        if ($attempt->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé',
            ], 403);
        }

        // Si la tentative n'est pas en cours, retourner 0
        if ($attempt->status !== 'in_progress') {
            return response()->json([
                'success' => true,
                'data' => [
                    'time_remaining_seconds' => 0,
                    'is_expired' => true,
                    'status' => $attempt->status,
                ],
            ]);
        }

        // Vérifier si le temps est écoulé
        $timeRemaining = $attempt->getTimeRemaining();
        $isExpired = $attempt->isTimeExpired();

        // Si expiré, soumettre automatiquement
        if ($isExpired) {
            $attempt->submit([]);

            return response()->json([
                'success' => false,
                'message' => 'Le temps est écoulé. Votre tentative a été soumise automatiquement.',
                'data' => [
                    'time_remaining_seconds' => 0,
                    'is_expired' => true,
                    'auto_submitted' => true,
                    'attempt' => $attempt->fresh(),
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'time_remaining_seconds' => $timeRemaining,
                'is_expired' => false,
                'started_at' => $attempt->started_at,
                'duration_minutes' => $attempt->quiz->duration_minutes,
            ],
        ]);
    }

    /**
     * POST /api/quiz-attempts/{id}/save-progress
     * Sauvegarder les réponses temporaires (sans soumettre)
     */
    public function saveProgress(SaveQuizProgressRequest $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Vérifier les permissions
        $user = $this->authenticatedUser($request);
        if ($attempt->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé',
            ], 403);
        }

        // Vérifier le statut
        if ($attempt->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Cette tentative ne peut plus être modifiée',
            ], 422);
        }

        // Vérifier si le temps est écoulé
        if ($attempt->isTimeExpired()) {
            $attempt->submit($request->answers ?? []);
            return response()->json([
                'success' => false,
                'message' => 'Le temps est écoulé. Votre tentative a été soumise automatiquement.',
                'data' => [
                    'time_expired' => true,
                    'attempt' => $attempt->fresh(),
                ],
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Sauvegarder les réponses temporaires
        $attempt->answers = $request->answers;
        $attempt->save();

        return response()->json([
            'success' => true,
            'message' => 'Progression sauvegardée',
            'data' => [
                'time_remaining' => $attempt->getTimeRemaining(),
                'saved_at' => now(),
            ],
        ]);
    }

    /**
     * Méthode helper: Obtenir les questions avec résultats
     */
    private function getQuestionsWithResults(QuizAttempt $attempt): array
    {
        $questions = $attempt->quiz->questions()->with('answers')->ordered()->get();
        $userAnswers = $attempt->answers;
        $gradingService = $this->gradingService;

        return $questions->map(function ($question) use ($userAnswers, $gradingService) {
            $userAnswer = $userAnswers[$question->id] ?? null;

            return [
                'question' => $question,
                'user_answer' => $userAnswer,
                'is_correct' => $gradingService->checkAnswer($question, $userAnswer),
                'points_earned' => $gradingService->calculatePoints($question, $userAnswer),
                'correct_answers' => $question->getCorrectAnswers(),
            ];
        })->toArray();
    }
}
