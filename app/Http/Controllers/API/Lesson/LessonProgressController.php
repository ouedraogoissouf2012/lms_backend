<?php

namespace App\Http\Controllers\API\Lesson;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\FilterLessonsRequest;
use App\Http\Requests\StoreLessonRequest;
use App\Http\Requests\UpdateLessonRequest;
use App\Http\Requests\DeleteLessonRequest;
use App\Http\Requests\PublishLessonRequest;
use App\Http\Requests\RateLessonRequest;
use App\Http\Requests\UnpublishLessonRequest;
use App\Http\Requests\UpdateLessonProgressRequest;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Services\Lesson\LessonProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * LessonProgressController — extrait verbatim de LessonController.
 * Refactor du god-controller (552 lignes -> 2 fichiers SRP).
 * Aucun changement comportemental.
 */
class LessonProgressController extends AuthenticatedController
{
    public function __construct(private LessonProgressService $progressService)
    {
    }

    /**
     * GET /api/lessons/{id}/progress
     * Obtenir la progression d'un cours (ou de tous les étudiants pour enseignant)
     */
    public function getProgress(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return $this->errorResponse('Cours non trouvé', 404);
        }

        $user = $this->authenticatedUser($request);

        // Étudiant: sa propre progression
        if ($user->isStudent()) {
            $progress = $this->progressService->progressForUser($lesson, $user->id);

            if (!$progress) {
                // Créer une nouvelle progression
                $progress = LessonProgress::create([
                    'user_id' => $user->id,
                    'lesson_id' => $lesson->id,
                ]);
            }

            return $this->successResponse($progress);
        }

        // Enseignant: progression de tous les étudiants
        $progresses = $lesson->progress()->with('user')->get();

        // Non migré vers successResponse() : expose une clé racine `statistics`
        // hors enveloppe que le trait ne reproduit pas (axe #1 « DRY-only »,
        // cf. LessonCrudController::myCourses).
        return response()->json([
            'success' => true,
            'data' => $progresses,
            'statistics' => [
                'total_students' => $progresses->count(),
                'students_started' => $this->progressService->studentsStartedCount($lesson),
                'students_completed' => $this->progressService->studentsCompletedCount($lesson),
                'average_completion_rate' => round($this->progressService->averageCompletionRate($lesson), 2),
            ],
        ]);
    }

    /**
     * POST /api/lessons/{id}/progress
     * Mettre à jour sa progression (Étudiants uniquement)
     */
    public function updateProgress(UpdateLessonProgressRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return $this->errorResponse('Cours non trouvé', 404);
        }

        $user = $this->authenticatedUser($request);

        // Trouver ou créer la progression
        $progress = LessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id]
        );

        $this->progressService->updateProgress(
            $progress,
            $request->progress_percentage,
            $request->time_spent_minutes
        );

        return $this->successResponse($progress->fresh(), 'Progression mise à jour');
    }

    /**
     * POST /api/lessons/{id}/complete
     * Marquer un cours comme complété (Étudiants uniquement)
     */
    public function markComplete(Request $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return $this->errorResponse('Cours non trouvé', 404);
        }

        $user = $this->authenticatedUser($request);

        $progress = LessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id]
        );

        $this->progressService->complete($progress);

        return $this->successResponse($progress->fresh(), 'Cours marqué comme complété');
    }

    /**
     * POST /api/lessons/{id}/rating
     * Noter un cours (Étudiants uniquement)
     */
    public function rate(RateLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::find($id);

        if (!$lesson) {
            return $this->errorResponse('Cours non trouvé', 404);
        }

        $user = $this->authenticatedUser($request);

        $progress = LessonProgress::firstOrCreate(
            ['user_id' => $user->id, 'lesson_id' => $lesson->id]
        );

        $this->progressService->addRating($progress, $request->rating, $request->feedback);

        return $this->successResponse($progress->fresh(), 'Note enregistrée');
    }
}
