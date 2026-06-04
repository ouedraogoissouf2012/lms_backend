<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Services\Chapter\ChapterProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller pour la progression utilisateur sur les chapitres.
 *
 * Toute la logique métier (lecture progression, accès gardé par quiz,
 * marquage complétion, cumul temps, reset) est portée par
 * `ChapterProgressService`. Ce controller se limite à :
 *   - extraire l'utilisateur typé via `authenticatedUser()`
 *   - valider l'input HTTP (FormRequest/`Request::validate`)
 *   - déléguer au service et formater la réponse JSON.
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict (jamais `app()`)
 * @see ChapterProgressService
 */
final class ChapterProgressController extends AuthenticatedController
{
    public function __construct(
        private readonly ChapterProgressService $progressService,
    ) {}

    /**
     * GET /lessons/{lessonId}/chapter-progress
     */
    public function getLessonProgress(Request $request, int $lessonId): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->progressService->getLessonProgress($lessonId, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * GET /chapters/{chapterId}/progress
     */
    public function getChapterProgress(Request $request, int $chapterId): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->progressService->getChapterProgress($chapterId, $user);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /chapters/{chapterId}/complete
     */
    public function markAsCompleted(Request $request, int $chapterId): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->progressService->markAsCompleted(
            $chapterId,
            $user,
            $request->only(['time_spent_seconds']),
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * Helper interne — pas de route publique associée (cf. PHPDoc du
     * service). Conservé pour parité comportementale et compatibilité
     * éventuelle avec `KnowledgeCheckController`. Si toujours sans
     * appelant au prochain passage, à passer en `private` ou retirer.
     */
    public function recordQuizScore(Request $request, int $chapterId, int $score, bool $passed): void
    {
        $user = $this->authenticatedUser($request);
        $this->progressService->recordQuizScore($chapterId, $score, $passed, $user);
    }

    /**
     * POST /chapters/{chapterId}/time
     */
    public function updateTimeSpent(Request $request, int $chapterId): JsonResponse
    {
        $user = $this->authenticatedUser($request);

        $validated = $request->validate([
            'time_spent_seconds' => 'required|integer|min:0',
        ]);

        $result = $this->progressService->updateTimeSpent(
            $chapterId,
            (int) $validated['time_spent_seconds'],
            $user,
        );

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * Reset progression d'une leçon (admin/enseignant).
     *
     * Note : pas de route enregistrée pour cet endpoint dans
     * `routes/api.php` au moment du split — conservé pour parité.
     */
    public function resetLessonProgress(Request $request, int $lessonId): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $rawTargetUserId = $request->input('user_id');
        $targetUserId = $rawTargetUserId !== null ? (int) $rawTargetUserId : null;

        $result = $this->progressService->resetLessonProgress($lessonId, $user, $targetUserId);

        return response()->json($result['payload'], $result['status']);
    }
}
