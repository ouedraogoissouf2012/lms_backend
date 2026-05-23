<?php

namespace App\Http\Controllers\API;

use App\Enums\Role;
use App\Http\Controllers\AuthenticatedController;
use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Http\Requests\ListFilesRequest;
use App\Http\Requests\UploadFileRequest;
use App\Http\Requests\UpdateFileRequest;
use App\Http\Requests\DeleteFileRequest;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller pour la gestion des fichiers
 */
class FileController extends AuthenticatedController
{
    use ChecksFileAuthorization;

    /**
     * Configuration — now standardized in UploadFileRequest
     * For reference: 30 MB max, strict MIME types (pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif)
     * See UploadFileRequest::rules() for validation contract
     */

    /**
     * GET /api/files
     * Liste des fichiers (avec filtres). Inputs whitelistés par ListFilesRequest.
     */
    public function index(ListFilesRequest $request): JsonResponse
    {
        $query = File::with(['user:id,name,email,role']);

        $user = $this->authenticatedUser($request);

        // Les étudiants ne voient que les fichiers publics ou leurs propres fichiers
        if ($user->isStudent()) {
            $query->where(function ($q) use ($user) {
                $q->where('is_public', true)
                  ->orWhere('user_id', $user->id);
            });
        }

        // Filtres — toutes les valeurs validées par ListFilesRequest.
        // fileable_type est dans la whitelist config/fileables.php (issue #10 IDOR).
        $validated = $request->validated();

        if (isset($validated['type'])) {
            $query->ofType($validated['type']);
        }

        if (isset($validated['category'])) {
            $query->ofCategory($validated['category']);
        }

        if (isset($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }

        if (isset($validated['fileable_type'], $validated['fileable_id'])) {
            $query->where('fileable_type', $validated['fileable_type'])
                  ->where('fileable_id', $validated['fileable_id']);
        }

        // Tri (validé par ListFilesRequest, default 'recent')
        $sortBy = $validated['sort'] ?? 'recent';
        switch ($sortBy) {
            case 'name':
                $query->orderBy('original_name', 'asc');
                break;
            case 'size':
                $query->orderBy('size_bytes', 'desc');
                break;
            case 'downloads':
                $query->orderBy('downloads_count', 'desc');
                break;
            default: // recent
                $query->orderBy('created_at', 'desc');
        }

        $perPage = $validated['per_page'] ?? 20;
        $files = $query->paginate($perPage);

        // Ajouter formatted_size à chaque fichier
        $files->getCollection()->transform(function ($file) {
            $file->formatted_size = $file->getFormattedSize();
            $file->download_url = $file->getDownloadUrl();
            return $file;
        });

        return response()->json([
            'success' => true,
            'data' => $files,
        ]);
    }

    /**
     * POST /api/files/upload
     * Upload d'un nouveau fichier (Standardized to 30 MB max via UploadFileRequest)
     */
    public function upload(UploadFileRequest $request): JsonResponse
    {
        // Validation handled by UploadFileRequest (30 MB, strict MIME types)
        $uploadedFile = $request->file('file');

        // Générer un nom unique
        $originalName = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();
        $storedName = Str::uuid() . '.' . $extension;

        // Déterminer le sous-dossier selon la catégorie
        $category = $request->get('category', 'general');
        $subfolder = match($category) {
            'course_material' => 'courses',
            'assignment' => 'assignments',
            'forum_attachment' => 'forum',
            'profile_picture' => 'profiles',
            default => 'general',
        };

        // Stocker le fichier
        $path = $uploadedFile->storeAs(
            "uploads/{$subfolder}",
            $storedName,
            'local'
        );

        if (!$path) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du stockage du fichier',
            ], 500);
        }

        // Créer l'enregistrement en base
        $mimeType = $uploadedFile->getMimeType();

        $file = File::create([
            'user_id' => $this->authenticatedUser($request)->id,
            'fileable_type' => $request->fileable_type,
            'fileable_id' => $request->fileable_id,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'path' => $path,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $uploadedFile->getSize(),
            'type' => File::determineTypeFromMime($mimeType),
            'category' => $category,
            'description' => $request->description,
            'is_public' => $request->boolean('is_public', false),
            'virus_scan_status' => 'clean', // TODO: Implémenter scan antivirus
        ]);

        $file->load('user:id,name,email');
        $file->formatted_size = $file->getFormattedSize();
        $file->download_url = $file->getDownloadUrl();

        return response()->json([
            'success' => true,
            'message' => 'Fichier uploadé avec succès',
            'data' => $file,
        ], 201);
    }

    /**
     * GET /api/files/{id}
     * Détails d'un fichier
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $file = File::with(['user:id,name,email,role', 'fileable'])->find($id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier non trouvé',
            ], 404);
        }

        // Authorization (tenant + ownership + admin + supradmin bypass) — issue #102 fix
        if (!$this->canReadFile($file, $this->authenticatedUser($request))) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé',
            ], 403);
        }

        $file->formatted_size = $file->getFormattedSize();
        $file->download_url = $file->getDownloadUrl();

        return response()->json([
            'success' => true,
            'data' => $file,
        ]);
    }

    /**
     * GET /api/files/{id}/download
     * Télécharger un fichier
     */
    public function download(Request $request, int $id): StreamedResponse|JsonResponse
    {
        $file = File::find($id);

        if (!$file) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier non trouvé',
            ], 404);
        }

        // Authorization (tenant + ownership + admin + supradmin bypass) — issue #102 fix
        if (!$this->canReadFile($file, $this->authenticatedUser($request))) {
            return response()->json([
                'success' => false,
                'message' => 'Accès refusé',
            ], 403);
        }

        // Vérifier que le fichier existe
        if (!$file->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Fichier physique introuvable',
            ], 404);
        }

        // Incrémenter le compteur
        $file->incrementDownloads();

        // Télécharger le fichier
        return Storage::disk('local')->download(
            $file->path,
            $file->original_name,
            ['Content-Type' => $file->mime_type]
        );
    }

    /**
     * PUT /api/files/{file}
     * Mettre à jour les métadonnées d'un fichier (propriétaire ou admin)
     */
    public function update(UpdateFileRequest $request, File $file): JsonResponse
    {
        $file->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Fichier mis à jour',
            'data' => $file->fresh(['user']),
        ]);
    }

    /**
     * DELETE /api/files/{file}
     * Supprimer un fichier (soft delete — propriétaire ou admin)
     */
    public function destroy(DeleteFileRequest $request, File $file): JsonResponse
    {
        $file->delete();

        return response()->json([
            'success' => true,
            'message' => 'Fichier supprimé',
        ]);
    }

    /**
     * GET /api/files/stats
     * Statistiques sur les fichiers.
     *
     * Tenant isolation (issue #102 fix):
     *   - supradmin sees global counts cross-tenant
     *   - non-supradmin sees counts scoped to their institution
     *   - students additionally see their own files only (within their tenant)
     *
     * Cache key namespaced per institution + role to prevent cross-tenant leak
     * via Cache::remember (same pattern as #98 NotificationsController::stats).
     */
    public function stats(Request $request): JsonResponse
    {
        $caller = $this->authenticatedUser($request);
        $isSupradmin = $caller->asRoleEnum() === Role::Supradmin;
        $isStudent = $caller->isStudent();

        $cacheKey = $isSupradmin
            ? 'files_stats_global'
            : ($isStudent
                ? "files_stats_inst_{$caller->institution_id}_user_{$caller->id}"
                : "files_stats_inst_{$caller->institution_id}");
        $cacheTTL = 300; // 5 minutes

        $applyScope = function ($query) use ($caller, $isSupradmin, $isStudent) {
            if ($isSupradmin) {
                return $query;
            }
            $query->where('institution_id', $caller->institution_id);
            if ($isStudent) {
                $query->where('user_id', $caller->id);
            }
            return $query;
        };

        $stats = Cache::remember($cacheKey, $cacheTTL, function () use ($applyScope) {
            $payload = [
                'total_files' => $applyScope(File::query())->count(),
                'total_size_bytes' => $applyScope(File::query())->sum('size_bytes'),
                'total_downloads' => $applyScope(File::query())->sum('downloads_count'),
                'by_type' => $applyScope(File::query())
                    ->selectRaw('type, COUNT(*) as count, SUM(size_bytes) as total_size')
                    ->groupBy('type')
                    ->get(),
                'by_category' => $applyScope(File::query())
                    ->selectRaw('category, COUNT(*) as count')
                    ->whereNotNull('category')
                    ->groupBy('category')
                    ->get(),
                'recent_uploads' => $applyScope(File::with('user:id,name'))
                    ->orderBy('created_at', 'desc')
                    ->limit(5)
                    ->get(),
            ];

            $totalSizeMB = round((float) $payload['total_size_bytes'] / (1024 * 1024), 2);
            $payload['total_size_formatted'] = $totalSizeMB . ' MB';

            return $payload;
        });

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
