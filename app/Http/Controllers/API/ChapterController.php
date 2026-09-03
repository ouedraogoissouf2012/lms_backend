<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\DeleteChapterRequest;
use App\Http\Requests\ReorderChaptersRequest;
use App\Http\Requests\RestoreChapterRequest;
use App\Http\Requests\StoreChapterRequest;
use App\Http\Requests\UpdateChapterRequest;
use App\Http\Requests\UploadFileRequest;
use App\Models\User;
use App\Services\Chapter\ChapterCrudService;
use App\Services\Chapter\ChapterFileUploadService;
use App\Services\Chapter\ChapterSlideService;
use App\Services\Chapter\ChapterTrashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Controller pour la gestion des chapitres.
 *
 * NOUVELLE ARCHITECTURE (INVERSÉE):
 *   - Lesson → hasMany → Chapters
 *   - Chapter → belongsTo → Lesson
 *   - Support upload PowerPoint / Word / PDF / Vidéo avec conversion
 *
 * Thin controller — toute la logique métier est déléguée aux services
 * SRP (`ChapterCrudService`, `ChapterFileUploadService`). Issu du split
 * de l'ancien `ChapterController` (316 lignes → ~110 lignes + 2 services).
 *
 * @see PRODUCTION_STANDARDS.md §5 — Controllers ≤200 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class ChapterController extends Controller
{
    public function __construct(
        private readonly ChapterCrudService $chapterCrud,
        private readonly ChapterFileUploadService $chapterFileUpload,
        private readonly ChapterSlideService $slides,
        private readonly ChapterTrashService $trash,
    ) {}

    /**
     * GET /api/lessons/{lessonId}/chapters
     * Liste des chapitres d'une leçon.
     */
    public function index(Request $request, int $lessonId): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $result = $this->chapterCrud->listByLesson($lessonId, $user);
        $result['payload'] = $this->slides->signListResponse($result['payload']);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * GET /api/chapters/{id}
     * Détails d'un chapitre avec sa leçon parente.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->errorResponse('Unauthenticated.', 401);
        }

        $result = $this->chapterCrud->show($id, $user);
        $result['payload'] = $this->slides->signSingleResponse($result['payload']);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lessons/{lessonId}/chapters
     * Créer un nouveau chapitre pour une leçon.
     */
    public function store(StoreChapterRequest $request, int $lessonId): JsonResponse
    {
        $result = $this->chapterCrud->create($request, $lessonId, auth()->id());

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/chapters/{chapterId}/upload
     * Upload et conversion de fichier pour un chapitre (30 MB max).
     */
    public function uploadFile(UploadFileRequest $request, int $chapterId): JsonResponse
    {
        $file = $request->file('file');
        if (! $file instanceof UploadedFile) {
            return response()->json(['success' => false, 'message' => 'Fichier requis.'], 422);
        }

        $result = $this->wantsAsync($request)
            ? $this->chapterFileUpload->uploadAsync($chapterId, $file, $request->user()?->id)
            : $this->chapterFileUpload->uploadAndProcess($chapterId, $file);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * GET /api/chapters/uploads/{id}/status
     * Statut d'une conversion fichier async.
     */
    public function uploadStatus(Request $request, string $id): JsonResponse
    {
        $result = $this->chapterFileUpload->asyncStatus($id, $request->user()?->id);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * PUT /api/chapters/{id}
     * Mettre à jour un chapitre.
     */
    public function update(UpdateChapterRequest $request, int $id): JsonResponse
    {
        $result = $this->chapterCrud->update($request, $id);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * DELETE /api/chapters/{id}
     * Supprimer un chapitre.
     */
    public function destroy(DeleteChapterRequest $request, int $id): JsonResponse
    {
        $result = $this->trash->trash($id);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/chapters/{id}/restore
     * Sortir un chapitre de la corbeille (#689).
     */
    public function restore(RestoreChapterRequest $request, int $id): JsonResponse
    {
        $result = $this->trash->restore($id);

        return response()->json($result['payload'], $result['status']);
    }

    /**
     * POST /api/lessons/{lessonId}/chapters/reorder
     * Réorganiser l'ordre des chapitres d'une leçon (drag & drop).
     */
    public function reorder(ReorderChaptersRequest $request, int $lessonId): JsonResponse
    {
        $result = $this->chapterCrud->reorder($request, $lessonId);

        return response()->json($result['payload'], $result['status']);
    }

    private function wantsAsync(UploadFileRequest $request): bool
    {
        return $request->boolean('async')
            || str_contains(strtolower((string) $request->header('Prefer')), 'respond-async');
    }
}
