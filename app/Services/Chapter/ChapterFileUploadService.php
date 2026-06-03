<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Services\FileConversionService;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ChapterFileUploadService — upload + conversion fichier chapitre extrait
 * verbatim de `ChapterController::uploadFile()`.
 *
 * Branchements supportés (selon l'extension du fichier) :
 *   - pptx / ppt   → FileConversionService::convertPowerPoint → slides_images
 *   - docx / doc   → FileConversionService::convertWord (ConvertAPI: images,
 *                    LibreOffice: HTML)
 *   - pdf          → FileConversionService::convertPdf → slides_images
 *   - mp4/avi/...  → stockage direct du fichier vidéo
 *
 * Aucun changement comportemental — logique extraite verbatim. Les MIME types
 * sont validés en amont par `UploadFileRequest` (30 MB max).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class ChapterFileUploadService
{
    /**
     * Extensions vidéo stockées telles quelles (sans conversion).
     *
     * @var array<int, string>
     */
    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'];

    public function __construct(
        private readonly FileConversionService $fileConversionService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Upload un fichier pour un chapitre et déclenche la conversion adéquate.
     *
     * @return array{status:int, payload: array<string, mixed>}
     */
    public function uploadAndProcess(int $chapterId, UploadedFile $file): array
    {
        try {
            $chapter = Chapter::findOrFail($chapterId);
            $extension = strtolower($file->getClientOriginalExtension());

            $this->logger->info('Upload fichier', [
                'chapter_id' => $chapterId,
                'filename' => $file->getClientOriginalName(),
                'size' => round($file->getSize() / 1024 / 1024, 2) . ' MB',
            ]);

            if (in_array($extension, ['pptx', 'ppt'], true)) {
                $this->handlePowerPoint($chapter, $file, $chapterId);
            } elseif (in_array($extension, ['docx', 'doc'], true)) {
                $this->handleWord($chapter, $file, $chapterId);
            } elseif ($extension === 'pdf') {
                $this->handlePdf($chapter, $file, $chapterId);
            } elseif (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                $this->handleVideo($chapter, $file, $chapterId);
            }

            return [
                'status' => 200,
                'payload' => [
                    'success' => true,
                    'data' => $chapter->fresh(),
                    'message' => 'Fichier uploadé et converti avec succès',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur upload fichier', [
                'chapter_id' => $chapterId,
                'error' => $e->getMessage(),
            ]);

            return [
                'status' => 500,
                'payload' => [
                    'success' => false,
                    'message' => 'Une erreur est survenue.',
                ],
            ];
        }
    }

    /**
     * Conversion PowerPoint (pptx/ppt) → images de slides.
     */
    private function handlePowerPoint(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $result = $this->fileConversionService->convertPowerPoint($file, $chapterId);

        $chapter->update([
            'content_type' => 'powerpoint',
            'file_original_path' => $result['file_original_path'],
            'file_converted_path' => $result['file_converted_path'],
            'slides_images' => $result['slides_images'],
            'slides_count' => $result['slides_count'],
        ]);

        $this->logger->info('PowerPoint converti', ['slides' => $result['slides_count']]);
    }

    /**
     * Conversion Word (docx/doc) — deux modes selon le backend :
     *   - ConvertAPI : Word → PDF → images de slides
     *   - LibreOffice : Word → HTML
     */
    private function handleWord(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $result = $this->fileConversionService->convertWord($file, $chapterId);

        if (isset($result['slides_images'])) {
            $chapter->update([
                'content_type' => 'word',
                'file_original_path' => $result['file_original_path'],
                'file_converted_path' => $result['file_converted_path'],
                'slides_images' => $result['slides_images'],
                'slides_count' => $result['slides_count'],
            ]);
            $this->logger->info('Word converti en images', ['slides' => $result['slides_count']]);
        } else {
            $chapter->update([
                'content_type' => 'word',
                'content' => $result['content'],
                'file_original_path' => $result['file_original_path'],
            ]);
            $this->logger->info('Word converti en HTML');
        }
    }

    /**
     * Conversion PDF → images de slides (rendu page par page).
     */
    private function handlePdf(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $result = $this->fileConversionService->convertPdf($file, $chapterId);

        $chapter->update([
            'content_type' => 'pdf',
            'file_original_path' => $result['file_original_path'],
            'file_converted_path' => $result['file_converted_path'],
            'slides_images' => $result['slides_images'],
            'slides_count' => $result['slides_count'],
            'pdf_url' => null, // Plus utilisé, on utilise slides_images
        ]);

        $this->logger->info('PDF converti en images', ['pages' => $result['slides_count']]);
    }

    /**
     * Stocke directement le fichier vidéo sans conversion.
     */
    private function handleVideo(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $videoPath = $file->store("chapters/{$chapterId}/video", 'public');

        $chapter->update([
            'content_type' => 'video',
            'file_original_path' => $videoPath,
            'video_url' => "/storage/{$videoPath}",
        ]);

        $this->logger->info('Vidéo stockée', ['path' => $videoPath]);
    }
}
