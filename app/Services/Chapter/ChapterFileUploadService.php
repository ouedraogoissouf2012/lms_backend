<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use Illuminate\Http\UploadedFile;
use LogicException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ChapterFileUploadService — upload + conversion fichier chapitre extrait
 * verbatim de `ChapterController::uploadFile()`.
 *
 * Orchestre l'upload : synchrone, asynchrone (job) et suivi de statut. Le choix
 * du convertisseur et l'écriture du résultat sur le chapitre appartiennent à
 * {@see ChapterConversionDispatcher}, extrait en #598 (§1.1 : ce fichier vivait
 * à 299 lignes sur 300, l'ajout d'une dépendance l'a fait déborder).
 *
 * Les MIME types sont validés en amont par `UploadFileRequest` (30 MB max).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services ≤300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class ChapterFileUploadService
{
    public function __construct(
        private readonly ChapterConversionDispatcher $dispatcher,
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
        $this->dispatcher->dispatch($chapter, $file);
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

}
