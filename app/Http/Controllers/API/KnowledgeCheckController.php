<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use App\Models\Chapter;
use App\Models\ChapterProgress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/**
 * Controller pour les quiz "Testez vos connaissances"
 * ATTENTION: Ceci est SEPARE des Evaluations KLASSCI
 */
class KnowledgeCheckController extends Controller
{
    /**
     * Liste des quiz d'un chapitre
     */
    public function index(Request $request): JsonResponse
    {
        $chapterId = $request->query('chapter_id');

        if (!$chapterId) {
            return response()->json([
                'success' => false,
                'message' => 'chapter_id requis'
            ], 400);
        }

        $quizzes = KnowledgeCheck::forChapter($chapterId)
            ->active()
            ->ordered()
            ->get();

        // Ajouter les infos de progression pour l'utilisateur connecte
        $userId = Auth::id();
        $quizzes = $quizzes->map(function ($quiz) use ($userId) {
            $quiz->user_passed = $quiz->isPassedByUser($userId);
            $quiz->user_best_score = $quiz->getBestScore($userId);
            $quiz->can_attempt = $quiz->canAttempt($userId);
            $quiz->questions_count = $quiz->questions_count;
            return $quiz;
        });

        return response()->json([
            'success' => true,
            'data' => $quizzes
        ]);
    }

    /**
     * Creer un nouveau quiz
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'chapter_id' => 'required|exists:chapters,id',
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'questions' => 'required|array|min:1',
            'questions.*.question' => 'required|string',
            'questions.*.type' => 'required|in:single,multiple,true_false',
            'questions.*.options' => 'required|array|min:2',
            'questions.*.correct_answer' => 'required',
            'questions.*.explanation' => 'nullable|string',
            'questions.*.points' => 'nullable|integer|min:1',
            'passing_score' => 'nullable|integer|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_options' => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'show_explanation' => 'nullable|boolean',
            'time_limit_minutes' => 'nullable|integer|min:1',
            'position' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation echouee',
                'errors' => $validator->errors()
            ], 422);
        }

        // Verifier que l'utilisateur a le droit de modifier ce chapitre
        $chapter = Chapter::findOrFail($request->chapter_id);
        $user = Auth::user();

        if (!$user->isAdmin() && $chapter->enseignant_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorise a modifier ce chapitre'
            ], 403);
        }

        $quiz = KnowledgeCheck::create($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Quiz cree avec succes',
            'data' => $quiz
        ], 201);
    }

    /**
     * Afficher un quiz specifique
     */
    public function show(string $id): JsonResponse
    {
        $quiz = KnowledgeCheck::with('chapter')->findOrFail($id);
        $userId = Auth::id();

        $quiz->user_passed = $quiz->isPassedByUser($userId);
        $quiz->user_best_score = $quiz->getBestScore($userId);
        $quiz->can_attempt = $quiz->canAttempt($userId);
        $quiz->attempts_count = $quiz->attempts()->where('user_id', $userId)->count();

        return response()->json([
            'success' => true,
            'data' => $quiz
        ]);
    }

    /**
     * Mettre a jour un quiz
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $quiz = KnowledgeCheck::findOrFail($id);
        $chapter = $quiz->chapter;
        $user = Auth::user();

        if (!$user->isAdmin() && $chapter->enseignant_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorise a modifier ce quiz'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'questions' => 'sometimes|required|array|min:1',
            'questions.*.question' => 'required_with:questions|string',
            'questions.*.type' => 'required_with:questions|in:single,multiple,true_false',
            'questions.*.options' => 'required_with:questions|array|min:2',
            'questions.*.correct_answer' => 'required_with:questions',
            'passing_score' => 'nullable|integer|min:0|max:100',
            'max_attempts' => 'nullable|integer|min:1',
            'shuffle_questions' => 'nullable|boolean',
            'shuffle_options' => 'nullable|boolean',
            'show_correct_answers' => 'nullable|boolean',
            'show_explanation' => 'nullable|boolean',
            'time_limit_minutes' => 'nullable|integer|min:1',
            'position' => 'nullable|integer|min:0',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation echouee',
                'errors' => $validator->errors()
            ], 422);
        }

        $quiz->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Quiz mis a jour',
            'data' => $quiz->fresh()
        ]);
    }

    /**
     * Supprimer un quiz
     */
    public function destroy(string $id): JsonResponse
    {
        $quiz = KnowledgeCheck::findOrFail($id);
        $chapter = $quiz->chapter;
        $user = Auth::user();

        if (!$user->isAdmin() && $chapter->enseignant_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorise a supprimer ce quiz'
            ], 403);
        }

        $quiz->delete();

        return response()->json([
            'success' => true,
            'message' => 'Quiz supprime'
        ]);
    }

    /**
     * Demarrer une tentative de quiz
     */
    public function startAttempt(string $id): JsonResponse
    {
        $quiz = KnowledgeCheck::findOrFail($id);
        $userId = Auth::id();

        if (!$quiz->canAttempt($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'Nombre maximum de tentatives atteint'
            ], 400);
        }

        // Preparer les questions (melanger si necessaire)
        $questions = $quiz->questions;

        if ($quiz->shuffle_questions) {
            shuffle($questions);
        }

        // Melanger les options si necessaire
        if ($quiz->shuffle_options) {
            foreach ($questions as &$q) {
                if (isset($q['options']) && is_array($q['options'])) {
                    shuffle($q['options']);
                }
            }
        }

        // Ne pas envoyer les reponses correctes
        $questionsForStudent = array_map(function ($q) {
            return [
                'question' => $q['question'],
                'type' => $q['type'],
                'options' => $q['options'],
                'points' => $q['points'] ?? 1,
            ];
        }, $questions);

        return response()->json([
            'success' => true,
            'data' => [
                'quiz_id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'questions' => $questionsForStudent,
                'time_limit_minutes' => $quiz->time_limit_minutes,
                'started_at' => now()->toIso8601String(),
            ]
        ]);
    }

    /**
     * Soumettre les reponses d'une tentative
     */
    public function submitAttempt(Request $request, string $id): JsonResponse
    {
        $quiz = KnowledgeCheck::findOrFail($id);
        $userId = Auth::id();

        $validator = Validator::make($request->all(), [
            'answers' => 'required|array',
            'time_spent_seconds' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation echouee',
                'errors' => $validator->errors()
            ], 422);
        }

        $userAnswers = $request->answers;
        $questions = $quiz->questions;
        $correctAnswers = 0;
        $totalQuestions = count($questions);
        $detailedAnswers = [];

        foreach ($questions as $index => $question) {
            $userAnswer = $userAnswers[$index] ?? null;
            $correctAnswer = $question['correct_answer'];
            $isCorrect = $this->checkAnswer($userAnswer, $correctAnswer, $question['type']);

            if ($isCorrect) {
                $correctAnswers++;
            }

            $detailedAnswers[] = [
                'question_index' => $index,
                'user_answer' => $userAnswer,
                'correct_answer' => $quiz->show_correct_answers ? $correctAnswer : null,
                'is_correct' => $isCorrect,
                'explanation' => $quiz->show_explanation ? ($question['explanation'] ?? null) : null,
            ];
        }

        $score = $totalQuestions > 0 ? round(($correctAnswers / $totalQuestions) * 100) : 0;
        $passed = $score >= $quiz->passing_score;

        $attempt = KnowledgeCheckAttempt::create([
            'knowledge_check_id' => $quiz->id,
            'user_id' => $userId,
            'score' => $score,
            'correct_answers' => $correctAnswers,
            'total_questions' => $totalQuestions,
            'answers' => $detailedAnswers,
            'time_spent_seconds' => $request->time_spent_seconds,
            'passed' => $passed,
            'started_at' => now()->subSeconds($request->time_spent_seconds),
            'completed_at' => now(),
        ]);

        // Enregistrer la progression du chapitre
        $chapterProgress = ChapterProgress::getOrCreate($userId, $quiz->chapter_id);

        // Mettre a jour le score (garder le meilleur)
        if ($chapterProgress->quiz_score === null || $score > $chapterProgress->quiz_score) {
            $chapterProgress->quiz_score = $score;
        }

        // Marquer comme reussi et complete si passe
        if ($passed) {
            $chapterProgress->quiz_passed = true;
            if (!$chapterProgress->completed_at) {
                $chapterProgress->completed_at = now();
            }
        }

        $chapterProgress->time_spent_seconds += $request->time_spent_seconds;
        $chapterProgress->save();

        return response()->json([
            'success' => true,
            'message' => $passed ? 'Felicitations! Vous avez reussi!' : 'Quiz termine. Continuez a vous entrainer!',
            'data' => [
                'attempt_id' => $attempt->id,
                'score' => $score,
                'correct_answers' => $correctAnswers,
                'total_questions' => $totalQuestions,
                'passed' => $passed,
                'passing_score' => $quiz->passing_score,
                'time_spent' => $attempt->formatted_duration,
                'answers' => $quiz->show_correct_answers ? $detailedAnswers : null,
                'can_retry' => $quiz->canAttempt($userId),
            ]
        ]);
    }

    /**
     * Verifier une reponse
     */
    private function checkAnswer($userAnswer, $correctAnswer, string $type): bool
    {
        if ($userAnswer === null) {
            return false;
        }

        switch ($type) {
            case 'single':
            case 'true_false':
                return $userAnswer === $correctAnswer;

            case 'multiple':
                if (!is_array($userAnswer) || !is_array($correctAnswer)) {
                    return false;
                }
                sort($userAnswer);
                sort($correctAnswer);
                return $userAnswer === $correctAnswer;

            default:
                return false;
        }
    }

    /**
     * Historique des tentatives d'un utilisateur pour un quiz
     */
    public function myAttempts(string $id): JsonResponse
    {
        $quiz = KnowledgeCheck::findOrFail($id);
        $userId = Auth::id();

        $attempts = KnowledgeCheckAttempt::where('knowledge_check_id', $id)
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($attempt) {
                return [
                    'id' => $attempt->id,
                    'score' => $attempt->score,
                    'correct_answers' => $attempt->correct_answers,
                    'total_questions' => $attempt->total_questions,
                    'passed' => $attempt->passed,
                    'time_spent' => $attempt->formatted_duration,
                    'completed_at' => $attempt->completed_at?->format('d/m/Y H:i'),
                ];
            });

        return response()->json([
            'success' => true,
            'data' => [
                'quiz_title' => $quiz->title,
                'passing_score' => $quiz->passing_score,
                'attempts' => $attempts,
                'best_score' => $attempts->max('score'),
                'has_passed' => $attempts->contains('passed', true),
            ]
        ]);
    }
}
