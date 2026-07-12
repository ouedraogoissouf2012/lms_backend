<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Services\FileConversionService;
use Illuminate\Http\UploadedFile;
use LogicException;
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
        private readonly AsyncChapterConversionDispatcher $asyncDispatcher,
        private readonly AsyncChapterConversionStore $asyncStore,
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

            $this->logger->info('Upload fichier', [
                'chapter_id' => $chapterId,
                'filename' => $file->getClientOriginalName(),
                'size' => round($file->getSize() / 1024 / 1024, 2).' MB',
            ]);

            $this->processUploadedFile($chapter, $file);

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

    /** @return array{status:int, payload: array<string, mixed>} */
    public function uploadAsync(int $chapterId, UploadedFile $file, ?int $userId): array
    {
        if ($userId === null || ! $this->isConvertible($file)) {
            return $this->uploadAndProcess($chapterId, $file);
        }

        try {
            Chapter::findOrFail($chapterId);
            $tracking = $this->asyncDispatcher->dispatch($chapterId, $file, $userId);

            return [
                'status' => 202,
                'payload' => [
                    'success' => true,
                    'data' => $tracking,
                    'message' => 'Conversion fichier acceptée et planifiée',
                ],
            ];
        } catch (Throwable $e) {
            $this->logger->error('Erreur planification conversion fichier', [
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

    /** @return array{status:int, payload: array<string, mixed>} */
    public function asyncStatus(string $id, ?int $userId): array
    {
        $payload = $this->asyncStore->get($id);
        if ($payload === null || $userId === null || $this->intPayload($payload, 'user_id') !== $userId) {
            return [
                'status' => 404,
                'payload' => [
                    'success' => false,
                    'message' => 'Conversion introuvable.',
                ],
            ];
        }

        return [
            'status' => 200,
            'payload' => [
                'success' => true,
                'data' => $this->publicStatus($payload),
            ],
        ];
    }

    public function processUploadedFile(Chapter $chapter, UploadedFile $file): void
    {
        $chapterId = $this->chapterId($chapter);
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['pptx', 'ppt'], true)) {
            $this->handlePowerPoint($chapter, $file, $chapterId);
        } elseif (in_array($extension, ['docx', 'doc'], true)) {
            $this->handleWord($chapter, $file, $chapterId);
        } elseif ($extension === 'pdf') {
            $this->handlePdf($chapter, $file, $chapterId);
        } elseif (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            $this->handleVideo($chapter, $file, $chapterId);
        }
    }

    private function isConvertible(UploadedFile $file): bool
    {
        return in_array(strtolower($file->getClientOriginalExtension()), [
            'pptx',
            'ppt',
            'docx',
            'doc',
            'pdf',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function publicStatus(array $payload): array
    {
        return [
            'id' => $payload['id'] ?? null,
            'chapter_id' => $payload['chapter_id'] ?? null,
            'status' => $payload['status'] ?? 'pending',
            'message' => $payload['message'] ?? null,
            'created_at' => $payload['created_at'] ?? null,
            'updated_at' => $payload['updated_at'] ?? null,
        ];
    }

    private function chapterId(Chapter $chapter): int
    {
        $id = $chapter->getKey();
        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        throw new LogicException('Chapter must have a numeric primary key.');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intPayload(array $payload, string $key): int
    {
        $value = $payload[$key] ?? null;
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new LogicException('Async conversion payload must contain numeric identifiers.');
    }

    /** Conversion PowerPoint (pptx/ppt) → images de slides. */
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
