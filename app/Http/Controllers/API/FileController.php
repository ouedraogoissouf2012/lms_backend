<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadFileRequest;
use App\Http\Requests\UpdateFileRequest;
use App\Http\Requests\DeleteFileRequest;
use App\Models\File;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Controller pour la gestion des fichiers
 */
class FileController extends Controller
{
    /**
     * Configuration — now standardized in UploadFileRequest
     * For reference: 30 MB max, strict MIME types (pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,gif)
     * See UploadFileRequest::rules() for validation contract
     */

    /**
     * GET /api/files
     * Liste des fichiers (avec filtres)
     */
    public function index(Request $request): JsonResponse
    {
        $query = File::with(['user:id,name,email,role'])->validated();

        $user = $request->user();

        // Les étudiants ne voient que les fichiers publics ou leurs propres fichiers
        if ($user->isStudent()) {
            $query->where(function ($q) use ($user) {
                $q->where('is_public', true)
                  ->orWhere('user_id', $user->id);
            });
        }

        // Filtres
        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        if ($request->has('category')) {
            $query->ofCategory($request->category);
        }

        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->has('fileable_type') && $request->has('fileable_id')) {
            $query->where('fileable_type', $request->fileable_type)
                  ->where('fileable_id', $request->fileable_id);
        }

        // Tri
        $sortBy = $request->get('sort', 'recent');
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

        $perPage = $request->get('per_page', 20);
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
            'user_id' => $request->user()->id,
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

        // Vérifier les permissions
        $user = $request->user();
        if (!$file->is_public && !$user->isAdmin() && $file->user_id !== $user->id) {
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

        // Vérifier les permissions
        $user = $request->user();
        if (!$file->is_public && !$user->isAdmin() && $file->user_id !== $user->id) {
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
     * Statistiques sur les fichiers
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = File::query();

        // Les étudiants ne voient que leurs propres stats
        if ($user->isStudent()) {
            $query->where('user_id', $user->id);
        }

        $stats = [
            'total_files' => $query->count(),
            'total_size_bytes' => $query->sum('size_bytes'),
            'total_downloads' => $query->sum('downloads_count'),
            'by_type' => $query->selectRaw('type, COUNT(*) as count, SUM(size_bytes) as total_size')
                ->groupBy('type')
                ->get(),
            'by_category' => $query->selectRaw('category, COUNT(*) as count')
                ->whereNotNull('category')
                ->groupBy('category')
                ->get(),
            'recent_uploads' => File::with('user:id,name')
                ->when($user->isStudent(), fn($q) => $q->where('user_id', $user->id))
                ->orderBy('created_at', 'desc')
                ->limit(5)
                ->get(),
        ];

        // Formater la taille totale
        $totalSizeMB = round($stats['total_size_bytes'] / (1024 * 1024), 2);
        $stats['total_size_formatted'] = $totalSizeMB . ' MB';

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
