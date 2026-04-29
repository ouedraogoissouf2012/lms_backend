<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\HandlesApiExceptions;
use App\Models\Chapter;
use App\Models\Lesson;
use App\Services\FileConversionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Controller pour la gestion des chapitres
 *
 * NOUVELLE ARCHITECTURE (INVERSÉE):
 * - Lesson → hasMany → Chapters
 * - Chapter → belongsTo → Lesson
 * - Support upload PowerPoint/Word avec conversion
 */
class ChapterController extends Controller
{
    use HandlesApiExceptions;
    protected $fileConversionService;

    public function __construct(FileConversionService $fileConversionService)
    {
        $this->fileConversionService = $fileConversionService;
    }
    /**
     * GET /api/lessons/{lessonId}/chapters
     * Liste des chapitres d'une leçon
     */
    public function index(int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $chapters = $lesson->chapters()->ordered()->get();

        return response()->json([
            'success' => true,
            'data' => $chapters,
            'message' => "Chapitres récupérés",
        ]);
    }

    /**
     * GET /api/chapters/{id}
     * Détails d'un chapitre avec sa leçon parente
     */
    public function show(int $id): JsonResponse
    {
        $chapter = Chapter::with('lesson')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $chapter
        ]);
    }

    /**
     * POST /api/lessons/{lessonId}/chapters
     * Créer un nouveau chapitre pour une leçon
     */
    public function store(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'content_type' => 'required|in:text,video,pdf,audio,presentation,powerpoint,word,excel,link,mixed',
            'content' => 'nullable|string',
            'video_url' => 'nullable|url',
            'video_provider' => 'nullable|in:youtube,vimeo,custom',
            'external_link' => 'nullable|url',
            'order' => 'nullable|integer|min:0',
            'duration_minutes' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'title', 'description', 'content_type', 'content',
            'video_url', 'video_provider', 'external_link',
            'order', 'duration_minutes'
        ]);

        $data['lesson_id'] = $lessonId;
        $data['matiere_id'] = $lesson->matiere_id;
        $data['enseignant_id'] = auth()->id();

        if (!isset($data['order'])) {
            $maxOrder = $lesson->chapters()->max('order') ?? 0;
            $data['order'] = $maxOrder + 1;
        }

        $chapter = Chapter::create($data);

        Log::info("✓ Chapitre créé", ['chapter_id' => $chapter->id, 'lesson_id' => $lessonId]);

        return response()->json([
            'success' => true,
            'data' => $chapter->load('lesson'),
            'message' => 'Chapitre créé avec succès'
        ], 201);
    }

    /**
     * POST /api/chapters/{chapterId}/upload
     * Upload et conversion de fichier pour un chapitre
     */
    public function uploadFile(Request $request, int $chapterId): JsonResponse
    {
        $chapter = Chapter::findOrFail($chapterId);

        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pptx,ppt,pdf,mp4,avi,mov,wmv,flv,webm,mkv|max:102400'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());

        Log::info("📤 Upload fichier", [
            'chapter_id' => $chapterId,
            'filename' => $file->getClientOriginalName(),
            'size' => round($file->getSize() / 1024 / 1024, 2) . ' MB'
        ]);

        if (in_array($extension, ['pptx', 'ppt'])) {
            $result = $this->fileConversionService->convertPowerPoint($file, $chapterId);

            $chapter->update([
                'content_type' => 'powerpoint',
                'file_original_path' => $result['file_original_path'],
                'file_converted_path' => $result['file_converted_path'],
                'slides_images' => $result['slides_images'],
                'slides_count' => $result['slides_count'],
            ]);

            Log::info("✓ PowerPoint converti", ['slides' => $result['slides_count']]);
        } elseif (in_array($extension, ['docx', 'doc'])) {
            $result = $this->fileConversionService->convertWord($file, $chapterId);

            if (isset($result['slides_images'])) {
                $chapter->update([
                    'content_type' => 'word',
                    'file_original_path' => $result['file_original_path'],
                    'file_converted_path' => $result['file_converted_path'],
                    'slides_images' => $result['slides_images'],
                    'slides_count' => $result['slides_count'],
                ]);
                Log::info("✓ Word converti en images", ['slides' => $result['slides_count']]);
            } else {
                $chapter->update([
                    'content_type' => 'word',
                    'content' => $result['content'],
                    'file_original_path' => $result['file_original_path'],
                ]);
                Log::info("✓ Word converti en HTML");
            }
        } elseif ($extension === 'pdf') {
            $result = $this->fileConversionService->convertPdf($file, $chapterId);

            $chapter->update([
                'content_type' => 'pdf',
                'file_original_path' => $result['file_original_path'],
                'file_converted_path' => $result['file_converted_path'],
                'slides_images' => $result['slides_images'],
                'slides_count' => $result['slides_count'],
                'pdf_url' => null,
            ]);

            Log::info("✓ PDF converti en images", ['pages' => $result['slides_count']]);
        } elseif (in_array($extension, ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'])) {
            $videoPath = $file->store("chapters/{$chapterId}/video", 'public');

            $chapter->update([
                'content_type' => 'video',
                'file_original_path' => $videoPath,
                'video_url' => "/storage/{$videoPath}",
            ]);

            Log::info("✓ Vidéo stockée", ['path' => $videoPath]);
        }

        return response()->json([
            'success' => true,
            'data' => $chapter->fresh(),
            'message' => 'Fichier uploadé et converti avec succès'
        ]);
    }

    /**
     * PUT /api/chapters/{id}
     * Mettre à jour un chapitre
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $chapter = Chapter::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'content' => 'nullable|string',
            'video_url' => 'nullable|url',
            'external_link' => 'nullable|url',
            'order' => 'nullable|integer|min:0',
            'duration_minutes' => 'nullable|integer|min:0',
            'allow_download' => 'nullable|boolean',
            'show_slide_numbers' => 'nullable|boolean',
            'autoplay_video' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $chapter->update($request->all());

        Log::info("✓ Chapitre mis à jour", ['chapter_id' => $id]);

        return response()->json([
            'success' => true,
            'data' => $chapter->fresh(),
            'message' => 'Chapitre mis à jour'
        ]);
    }

    /**
     * DELETE /api/chapters/{id}
     * Supprimer un chapitre
     */
    public function destroy(int $id): JsonResponse
    {
        $chapter = Chapter::findOrFail($id);

        $this->fileConversionService->deleteChapterFiles($id);

        $chapter->delete();

        Log::info("✓ Chapitre supprimé", ['chapter_id' => $id]);

        return response()->json([
            'success' => true,
            'message' => 'Chapitre supprimé'
        ]);
    }

    /**
     * POST /api/lessons/{lessonId}/chapters/reorder
     * Réorganiser l'ordre des chapitres d'une leçon (drag & drop)
     */
    public function reorder(Request $request, int $lessonId): JsonResponse
    {
        $lesson = Lesson::findOrFail($lessonId);

        $validator = Validator::make($request->all(), [
            'chapters' => 'required|array',
            'chapters.*.id' => 'required|exists:chapters,id',
            'chapters.*.order' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        foreach ($request->chapters as $chapterData) {
            Chapter::where('id', $chapterData['id'])
                ->where('lesson_id', $lessonId)
                ->update(['order' => $chapterData['order']]);
        }

        Log::info("✓ Chapitres réorganisés", ['lesson_id' => $lessonId, 'count' => count($request->chapters)]);

        return response()->json([
            'success' => true,
            'message' => 'Ordre des chapitres mis à jour'
        ]);
    }
}
