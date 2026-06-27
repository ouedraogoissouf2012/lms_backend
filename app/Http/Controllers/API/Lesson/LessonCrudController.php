<?php

declare(strict_types=1);

namespace App\Http\Controllers\API\Lesson;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\DeleteLessonRequest;
use App\Http\Requests\FilterLessonsRequest;
use App\Http\Requests\PublishLessonRequest;
use App\Http\Requests\StoreLessonRequest;
use App\Http\Requests\UnpublishLessonRequest;
use App\Http\Requests\UpdateLessonRequest;
use App\Models\Lesson;
use App\Services\Lesson\LessonCrudOperationsService;
use App\Services\Lesson\LessonListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Thin controller — endpoints CRUD leçons.
 *
 * Refactor split-15 : la logique métier (lecture filtrée + mutations +
 * notifications de publication) est déléguée à deux services SRP
 * (`LessonListService`, `LessonCrudOperationsService`). Le controller se
 * limite à : extraire l'input, déléguer, formater la réponse HTTP.
 *
 * §5 ≤200 lignes — §1.6 D DI strict (services injectés par constructeur,
 * pas de `app()`, pas de Facade `Log`).
 */
final class LessonCrudController extends AuthenticatedController
{
    public function __construct(
        private readonly LessonListService $listService,
        private readonly LessonCrudOperationsService $operationsService,
    ) {
    }

    /**
     * GET /api/lessons
     * Liste des cours (avec filtres optionnels).
     */
    public function index(FilterLessonsRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $lessons = $this->listService->list($request, $user);

        return $this->successResponse($lessons);
    }

    /**
     * GET /api/lessons/my-courses
     * Liste des cours de l'étudiant connecté avec enseignant et matière.
     * Filtrable par matiere_id et enseignant_id.
     */
    public function myCourses(Request $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->listService->myCourses($request, $user);

        // Non migré vers successResponse() : cette réponse expose des clés
        // racine hors enveloppe (`filters`, `total`) que le trait ne reproduit
        // pas. Les y déplacer (sous `meta`) changerait le contrat client →
        // conservé tel quel (axe #1 « DRY-only », préservation de la sortie).
        return response()->json([
            'success' => true,
            'data' => $result['courses'],
            'filters' => $result['filters'],
            'total' => $result['total'],
        ]);
    }

    /**
     * GET /api/lessons/{id}
     * Détails d'un cours.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $result = $this->listService->show($user, $id);

        if (isset($result['error'])) {
            return $this->errorResponse($result['error'], (int) $result['status']);
        }

        return $this->successResponse($result['lesson']);
    }

    /**
     * POST /api/lessons
     * Créer un nouveau cours (Enseignants uniquement).
     */
    public function store(StoreLessonRequest $request): JsonResponse
    {
        $user = $this->authenticatedUser($request);
        $lesson = $this->operationsService->create($request->validated(), $user);

        return $this->successResponse($lesson, 'Cours créé avec succès', 201);
    }

    /**
     * PUT /api/lessons/{id}
     * Mettre à jour un cours (Enseignants uniquement).
     */
    public function update(UpdateLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $lesson = $this->operationsService->update($lesson, $request->validated());

        return $this->successResponse($lesson, 'Cours mis à jour avec succès');
    }

    /**
     * DELETE /api/lessons/{id}
     * Supprimer un cours (Enseignants uniquement).
     */
    public function destroy(DeleteLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $this->operationsService->delete($lesson);

        return $this->successResponse(null, 'Cours supprimé avec succès');
    }

    /**
     * POST /api/lessons/{id}/publish
     * Publier un cours (Enseignants uniquement).
     */
    public function publish(PublishLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $lesson = $this->operationsService->publish($lesson);

        return $this->successResponse($lesson, 'Cours publié avec succès');
    }

    /**
     * POST /api/lessons/{id}/unpublish
     * Dépublier un cours (Enseignants uniquement).
     */
    public function unpublish(UnpublishLessonRequest $request, int $id): JsonResponse
    {
        $lesson = Lesson::findOrFail($id);
        $lesson = $this->operationsService->unpublish($lesson);

        return $this->successResponse($lesson, 'Cours remis en brouillon');
    }
}
