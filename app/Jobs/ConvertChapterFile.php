<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Chapter;
use App\Services\Chapter\AsyncChapterConversionStore;
use App\Services\Chapter\ChapterFileUploadService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class ConvertChapterFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        private readonly string $conversionId,
    ) {}

    public function handle(
        AsyncChapterConversionStore $store,
        ChapterFileUploadService $uploads,
        LoggerInterface $logger,
    ): void {
        $payload = $store->get($this->conversionId);
        if ($payload === null || ($payload['status'] ?? null) === 'completed') {
            return;
        }

        $sourcePath = $this->stringMeta($payload, 'source_path');
        if ($sourcePath === null || ! Storage::disk('local')->exists($sourcePath)) {
            $store->failed($this->conversionId, 'source_file_missing');

            return;
        }

        $store->running($this->conversionId);

        try {
            $chapterId = $this->intPayload($payload, 'chapter_id');
            $chapter = Chapter::findOrFail($chapterId);
            $uploads->processUploadedFile($chapter, $this->uploadedFile($payload, $sourcePath));
            $store->completed($this->conversionId, $chapterId);
            Storage::disk('local')->delete($sourcePath);
        } catch (Throwable $e) {
            $logger->error('Async chapter conversion failed', [
                'conversion_id' => $this->conversionId,
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(AsyncChapterConversionStore::class)->failed(
            $this->conversionId,
            'chapter_conversion_failed'
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function uploadedFile(array $payload, string $sourcePath): UploadedFile
    {
        return new UploadedFile(
            Storage::disk('local')->path($sourcePath),
            $this->stringMeta($payload, 'original_name') ?? 'upload.bin',
            $this->stringMeta($payload, 'mime_type'),
            null,
            true
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringMeta(array $payload, string $key): ?string
    {
        $meta = $payload['meta'] ?? [];
        if (! is_array($meta) || ! isset($meta[$key]) || ! is_string($meta[$key])) {
            return null;
        }

        return $meta[$key];
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

        throw new RuntimeException('Invalid async conversion payload');
    }
}
