<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Http\Requests\DeleteFileRequest;
use App\Http\Requests\ListFilesRequest;
use App\Http\Requests\UpdateFileRequest;
use App\Http\Requests\UploadFileRequest;
use App\Models\File;
use App\Services\File\FileMutationService;
use App\Services\File\FileQueryService;
use App\Services\File\FileUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller thin pour la gestion des fichiers — délègue à 3 services SRP.
 *
 * Split-16/file (§5 controllers ≤200 lignes, §1.1 services ≤300 lignes) :
 *   - {@see FileQueryService}    — index, show, stats (lectures + cache)
 *   - {@see FileUploadService}   — upload (storage + persistance)
 *   - {@see FileMutationService} — update, destroy, download
 *
 * L'autorisation reste ici via le trait {@see ChecksFileAuthorization} pour
 * `show` / `download` (les autres endpoints passent par les FormRequests
 * `UpdateFileRequest` / `DeleteFileRequest::authorize()`).
 *
 * @see PRODUCTION_STANDARDS.md §5 + §1.1 + §1.6 D
 */
final class FileController extends AuthenticatedController
{
    use ChecksFileAuthorization;

    public function __construct(
        private readonly FileQueryService $queryService,
        private readonly FileUploadService $uploadService,
        private readonly FileMutationService $mutationService,
    ) {}

    /**
     * GET /api/files — liste paginée filtrée. Inputs whitelistés par `ListFilesRequest`.
     */
    public function index(ListFilesRequest $request): JsonResponse
    {
        $files = $this->queryService->list(
            $this->authenticatedUser($request),
            $request->validated(),
        );

        return response()->json([
            'success' => true,
            'data' => $files,
        ]);
    }

    /**
     * POST /api/files/upload — upload (30 MB max, MIME whitelist via UploadFileRequest).
     */
    public function upload(UploadFileRequest $request): JsonResponse
    {
        $uploaded = $request->file('file');
        if (! is_object($uploaded)) {
            return response()->json([
                'success' => false,
                'message' => 'Un fichier est requis',
            ], 422);
        }

        $result = $this->uploadService->store(
            $uploaded,
            $this->authenticatedUser($request),
            $request->validated(),
        );

        if ($result['file'] === null) {
            return response()->json([
                'success' => false,
                'message' => $result['error'] ?? 'Erreur lors du stockage du fichier',
            ], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Fichier uploadé avec succès',
            'data' => $result['file'],
        ], 201);
    }

    /**
     * GET /api/files/{id} — détails d'un fichier.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $file = $this->queryService->find($id);

        if ($file === null) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier non trouvé',
            ], 404);
        }

        if (! $this->canReadFile($file, $this->authenticatedUser($request))) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $file,
        ]);
    }

    /**
     * GET /api/files/{id}/download — téléchargement streamé.
     */
    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $file = File::find($id);

        if ($file === null) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier non trouvé',
            ], 404);
        }

        if (! $this->canReadFile($file, $this->authenticatedUser($request))) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé',
            ], 403);
        }

        $stream = $this->mutationService->download($file);

        if ($stream === null) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier physique introuvable',
            ], 404);
        }

        return $stream;
    }

    /**
     * PUT /api/files/{file} — mise à jour des métadonnées (autorisé par UpdateFileRequest).
     */
    public function update(UpdateFileRequest $request, File $file): JsonResponse
    {
        $updated = $this->mutationService->update($file, $request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Fichier mis à jour',
            'data' => $updated,
        ]);
    }

    /**
     * DELETE /api/files/{file} — soft delete (autorisé par DeleteFileRequest).
     */
    public function destroy(DeleteFileRequest $request, File $file): JsonResponse
    {
        $this->mutationService->destroy($file);

        return response()->json([
            'success' => true,
            'message' => 'Fichier supprimé',
        ]);
    }

    /**
     * GET /api/files/stats — agrégations, cache namespacé par tenant + rôle.
     */
    public function stats(Request $request): JsonResponse
    {
        $stats = $this->queryService->stats($this->authenticatedUser($request));

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
