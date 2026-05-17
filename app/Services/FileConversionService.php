<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\FileConversion\PdfConverter;
use App\Services\FileConversion\PowerPointConverter;
use App\Services\FileConversion\WordConverter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Façade preserving the legacy public API consumed by `ChapterController`,
 * delegating each conversion to a single-responsibility converter under
 * `App\Services\FileConversion\`.
 *
 * Refacto #79 Phase B : the original 586-line monolith handled four
 * conversion types and shell execution inline. This class now only
 * routes calls — the heavy lifting lives in {@see PowerPointConverter},
 * {@see WordConverter}, {@see PdfConverter} and their helpers
 * ({@see \App\Services\FileConversion\PdfToPngRenderer},
 * {@see \App\Services\FileConversion\FileValidator},
 * {@see \App\Support\Shell\ShellExecutor}).
 *
 * **API stability (Invariant 1)** : method signatures, return shapes and
 * `conversion_method` strings are unchanged versus the legacy version.
 * `ChapterController` keeps injecting `FileConversionService` and calling
 * the same methods — no controller change needed.
 */
final class FileConversionService
{
    public function __construct(
        private readonly PowerPointConverter $powerPoint,
        private readonly WordConverter $word,
        private readonly PdfConverter $pdf,
    ) {
    }

    /**
     * @return array{success: bool, slides_images: list<string>, slides_count: int, file_original_path: string, file_converted_path: string, conversion_method: string}
     */
    public function convertPowerPoint(UploadedFile $file, int $chapterId): array
    {
        return $this->powerPoint->convert($file, $chapterId);
    }

    /**
     * @return array{success: bool, content: string, content_type: string, file_original_path: string}
     */
    public function convertWord(UploadedFile $file, int $chapterId): array
    {
        return $this->word->convert($file, $chapterId);
    }

    /**
     * @return array{success: bool, slides_images: list<string>, slides_count: int, file_original_path: string, file_converted_path: string, conversion_method: string}
     */
    public function convertPdf(UploadedFile $file, int $chapterId): array
    {
        return $this->pdf->convert($file, $chapterId);
    }

    /**
     * Delete the public-disk directory holding every artefact tied to a chapter
     * (original upload, intermediate PDFs/HTMLs, generated slides).
     *
     * Behaviour preserved verbatim from the legacy method.
     */
    public function deleteChapterFiles(int $chapterId): void
    {
        Storage::disk('public')->deleteDirectory("chapters/{$chapterId}");

        Log::info('🗑️ Fichiers chapitre supprimés', ['chapter_id' => $chapterId]);
    }

    /**
     * Return descriptive metadata for an uploaded file.
     * Pure helper kept for backward compatibility with the legacy public API.
     *
     * @return array{original_name: string, extension: string, mime_type: string|null, size: int|false, size_mb: float}
     */
    public function getFileInfo(UploadedFile $file): array
    {
        $size = $file->getSize();

        return [
            'original_name' => $file->getClientOriginalName(),
            'extension'     => $file->getClientOriginalExtension(),
            'mime_type'     => $file->getMimeType(),
            'size'          => $size,
            'size_mb'       => round(($size === false ? 0 : $size) / 1024 / 1024, 2),
        ];
    }
}
