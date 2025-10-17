<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizQuestion;
use App\Models\QuizAnswer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

/**
 * Controller pour la gestion des quiz
 */
class QuizController extends Controller
{
    /**
     * GET /api/quizzes
     * Liste des quiz (avec filtres)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Quiz::with(['creator:id,name,email,role', 'matiere:id,libelle', 'classe:id,libelle']);

        $user = $request->user();

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
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'instructions' => 'nullable|string',
            'lesson_id' => 'nullable|exists:lessons,id',
            'matiere_id' => 'nullable|exists:matieres,id',
            'classe_id' => 'nullable|exists:classes,id',
            'type' => 'nullable|in:formative,summative,diagnostic',
            'duration_minutes' => 'nullable|integer|min:1',
            'max_attempts' => 'nullable|integer|min:1',
            'passing_score' => 'nullable|numeric|min:0|max:100',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_answers' => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'allow_review' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = $request->user()->id;

        $quiz = Quiz::create($data);
        $quiz->load('creator', 'matiere', 'classe');

        return response()->json([
            'success' => true,
            'message' => 'Quiz créé avec succès',
            'data' => $quiz,
        ], 201);
    }

    /**
     * GET /api/quizzes/{id}
     * Détails d'un quiz
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $quiz = Quiz::with([
            'creator:id,name,email,role',
            'matiere:id,libelle',
            'classe:id,libelle',
            'lesson:id,title',
        ])->find($id);

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz non trouvé',
            ], 404);
        }

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
     * PUT /api/quizzes/{id}
     * Mettre à jour un quiz
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz non trouvé',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
        if (!$user->isAdmin() && $quiz->created_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à modifier ce quiz',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'instructions' => 'sometimes|string',
            'duration_minutes' => 'sometimes|integer|min:1',
            'max_attempts' => 'sometimes|integer|min:1',
            'passing_score' => 'sometimes|numeric|min:0|max:100',
            'shuffle_questions' => 'sometimes|boolean',
            'shuffle_answers' => 'sometimes|boolean',
            'show_correct_answers' => 'sometimes|boolean',
            'allow_review' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $quiz->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Quiz mis à jour',
            'data' => $quiz->fresh(['creator', 'matiere', 'classe']),
        ]);
    }

    /**
     * DELETE /api/quizzes/{id}
     * Supprimer un quiz
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz non trouvé',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
        if (!$user->isAdmin() && $quiz->created_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé à supprimer ce quiz',
            ], 403);
        }

        $quiz->delete();

        return response()->json([
            'success' => true,
            'message' => 'Quiz supprimé',
        ]);
    }

    /**
     * POST /api/quizzes/{id}/publish
     * Publier un quiz
     */
    public function publish(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz non trouvé',
            ], 404);
        }

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

    /**
     * POST /api/quizzes/{id}/start
     * Démarrer une nouvelle tentative de quiz
     */
    public function startAttempt(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz non trouvé',
            ], 404);
        }

        $user = $request->user();

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
    public function submitAttempt(Request $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
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
            $attempt->submit($request->answers ?? []);
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
        $attempt->submit($request->answers);

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
    public function showAttempt(Request $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with(['quiz', 'user:id,name,email'])->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
        if (!$user->isAdmin() && $attempt->user_id !== $user->id && $attempt->quiz->created_by !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas autorisé',
            ], 403);
        }

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
     * GET /api/quizzes/{id}/attempts
     * Liste des tentatives pour un quiz (Enseignants)
     */
    public function getAttempts(Request $request, int $id): JsonResponse
    {
        $quiz = Quiz::find($id);

        if (!$quiz) {
            return response()->json([
                'success' => false,
                'message' => 'Quiz non trouvé',
            ], 404);
        }

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
    public function gradeAttempt(Request $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'points_earned' => 'required|numeric|min:0',
            'feedback' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Données invalides',
                'errors' => $validator->errors(),
            ], 422);
        }

        $attempt->manualGrade(
            $request->points_earned,
            $request->user()->id,
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
    public function checkTimeRemaining(Request $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
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
    public function saveProgress(Request $request, int $id): JsonResponse
    {
        $attempt = QuizAttempt::with('quiz')->find($id);

        if (!$attempt) {
            return response()->json([
                'success' => false,
                'message' => 'Tentative non trouvée',
            ], 404);
        }

        // Vérifier les permissions
        $user = $request->user();
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

        return $questions->map(function ($question) use ($userAnswers) {
            $userAnswer = $userAnswers[$question->id] ?? null;
            $isCorrect = $question->checkAnswer($userAnswer);

            return [
                'question' => $question,
                'user_answer' => $userAnswer,
                'is_correct' => $isCorrect,
                'points_earned' => $question->calculatePoints($userAnswer),
                'correct_answers' => $question->getCorrectAnswers(),
            ];
        })->toArray();
    }
}
