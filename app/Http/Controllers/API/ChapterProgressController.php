<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Models\Chapter;
use App\Models\ChapterProgress;
use App\Models\KnowledgeCheck;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * Controller pour la progression des chapitres
 */
class ChapterProgressController extends AuthenticatedController
{
    /**
     * Obtenir la progression d'une lecon pour l'utilisateur connecte
     */
    public function getLessonProgress(Request $request, int $lessonId): JsonResponse
    {
        $userId = $this->authenticatedUser($request)->id;
        $progress = ChapterProgress::getLessonProgress($userId, $lessonId);

        return response()->json([
            'success' => true,
            'data' => $progress,
        ]);
    }

    /**
     * Obtenir la progression d'un chapitre specifique
     */
    public function getChapterProgress(Request $request, int $chapterId): JsonResponse
    {
        $userId = $this->authenticatedUser($request)->id;
        $chapter = Chapter::findOrFail($chapterId);

        $progress = ChapterProgress::where('user_id', $userId)
            ->where('chapter_id', $chapterId)
            ->first();

        // Verifier si l'utilisateur peut acceder a ce chapitre
        $canAccess = ChapterProgress::canAccessChapter($userId, $chapter);

        // Verifier si le chapitre precedent a un quiz obligatoire non reussi
        $blockedByQuiz = null;
        if (!$canAccess) {
            $previousChapter = Chapter::where('lesson_id', $chapter->lesson_id)
                ->where('position', '<', $chapter->position)
                ->orderBy('position', 'desc')
                ->first();

            if ($previousChapter && $previousChapter->content_type === 'quiz') {
                $quiz = KnowledgeCheck::where('chapter_id', $previousChapter->id)
                    ->where('is_active', true)
                    ->where('is_required', true)
                    ->first();

                if ($quiz) {
                    $blockedByQuiz = [
                        'chapter_id' => $previousChapter->id,
                        'chapter_title' => $previousChapter->title,
                        'quiz_title' => $quiz->title,
                        'passing_score' => $quiz->passing_score,
                    ];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'chapter_id' => $chapterId,
                'is_completed' => $progress?->isCompleted() ?? false,
                'completed_at' => $progress?->completed_at?->toIso8601String(),
                'time_spent_seconds' => $progress?->time_spent_seconds ?? 0,
                'quiz_score' => $progress?->quiz_score,
                'quiz_passed' => $progress?->quiz_passed ?? false,
                'can_access' => $canAccess,
                'blocked_by_quiz' => $blockedByQuiz,
            ],
        ]);
    }

    /**
     * Marquer un chapitre comme complete
     */
    public function markAsCompleted(Request $request, int $chapterId): JsonResponse
    {
        $userId = $this->authenticatedUser($request)->id;
        $chapter = Chapter::findOrFail($chapterId);

        // Verifier si l'utilisateur peut acceder a ce chapitre
        if (!ChapterProgress::canAccessChapter($userId, $chapter)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez d\'abord completer le chapitre precedent',
            ], 403);
        }

        // Si c'est un quiz, verifier qu'il est reussi
        if ($chapter->content_type === 'quiz') {
            $quiz = KnowledgeCheck::where('chapter_id', $chapterId)
                ->where('is_active', true)
                ->first();

            if ($quiz && $quiz->is_required) {
                $progress = ChapterProgress::where('user_id', $userId)
                    ->where('chapter_id', $chapterId)
                    ->first();

                if (!$progress || !$progress->quiz_passed) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Vous devez reussir le quiz pour completer ce chapitre',
                    ], 400);
                }
            }
        }

        $progress = ChapterProgress::getOrCreate($userId, $chapterId);

        // Mettre a jour le temps passe si fourni
        if ($request->has('time_spent_seconds')) {
            $progress->time_spent_seconds += $request->input('time_spent_seconds', 0);
        }

        $progress->markAsCompleted();

        // Calculer la progression globale de la lecon
        $lessonProgress = ChapterProgress::getLessonProgress($userId, $chapter->lesson_id);

        return response()->json([
            'success' => true,
            'message' => 'Chapitre marque comme complete',
            'data' => [
                'chapter_progress' => [
                    'is_completed' => true,
                    'completed_at' => $progress->completed_at->toIso8601String(),
                ],
                'lesson_progress' => $lessonProgress,
            ],
        ]);
    }

    /**
     * Enregistrer le score d'un quiz.
     *
     * @internal Method has no current callers (grep confirms 0 hit). PHPDoc mentions
     *           "appele automatiquement par KnowledgeCheckController" but no such
     *           call exists. Kept for now in case future integration uses it; the
     *           `Request` parameter was added in Batch 9 (#102 fix-pattern) for
     *           consistency with the AuthenticatedController contract. If still
     *           dead code at next refactor pass, mark private or remove.
     */
    public function recordQuizScore(Request $request, int $chapterId, int $score, bool $passed): void
    {
        $userId = $this->authenticatedUser($request)->id;
        $progress = ChapterProgress::getOrCreate($userId, $chapterId);

        // Mettre a jour le score (garder le meilleur)
        if ($progress->quiz_score === null || $score > $progress->quiz_score) {
            $progress->quiz_score = $score;
        }

        // Marquer comme reussi si passe
        if ($passed) {
            $progress->quiz_passed = true;
            $progress->markAsCompleted();
        }

        $progress->save();
    }

    /**
     * Mettre a jour le temps passe sur un chapitre
     */
    public function updateTimeSpent(Request $request, int $chapterId): JsonResponse
    {
        $userId = $this->authenticatedUser($request)->id;
        $chapter = Chapter::findOrFail($chapterId);

        $request->validate([
            'time_spent_seconds' => 'required|integer|min:0',
        ]);

        $progress = ChapterProgress::getOrCreate($userId, $chapterId);
        $progress->time_spent_seconds += $request->input('time_spent_seconds');
        $progress->save();

        return response()->json([
            'success' => true,
            'data' => [
                'time_spent_seconds' => $progress->time_spent_seconds,
            ],
        ]);
    }

    /**
     * Reinitialiser la progression d'une lecon (admin/enseignant seulement)
     */
    public function resetLessonProgress(Request $request, int $lessonId): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        // Verifier les permissions (latent typo: isEnseignant() did not exist on User —
        // fixed to isTeacher() which is the actual method, cf. User.php:115).
        if (!$user->isAdmin() && !$user->isTeacher()) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorise',
            ], 403);
        }

        $targetUserId = $request->input('user_id');

        // Si pas d'user_id specifie, reinitialiser pour l'utilisateur connecte
        if (!$targetUserId) {
            $targetUserId = $user->id;
        }

        // Un enseignant ne peut reinitialiser que sa propre progression
        if (!$user->isAdmin() && $targetUserId !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Non autorise a reinitialiser la progression d\'un autre utilisateur',
            ], 403);
        }

        $chapters = Chapter::where('lesson_id', $lessonId)->pluck('id');

        ChapterProgress::where('user_id', $targetUserId)
            ->whereIn('chapter_id', $chapters)
            ->delete();

        return response()->json([
            'success' => true,
            'message' => 'Progression reinitialised',
        ]);
    }
}
