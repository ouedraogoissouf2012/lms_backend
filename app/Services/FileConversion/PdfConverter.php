<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

use App\Services\ConvertApiService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Converts an uploaded `.pdf` to a sequence of PNG slides.
 *
 * Pipeline (mirroring the legacy `FileConversionService::convertPdf`) :
 *  1. Validate the upload (size + extension + MIME) via {@see FileValidator}.
 *  2. Persist the original under `chapters/{chapterId}/original/`.
 *  3. Try ConvertAPI first (one round-trip, no local binaries needed).
 *  4. On ConvertAPI failure, delegate to {@see PdfToPngRenderer} which
 *     auto-selects Imagick (preferred) or Ghostscript locally.
 *
 * No {@see \App\Support\Shell\ShellExecutor} direct dependency : the shell
 * (Ghostscript path) is reached only through `PdfToPngRenderer`. This keeps
 * `PdfConverter` decoupled from the shell layer and clarifies its single
 * responsibility (drive the ConvertAPI/local fallback decision).
 *
 * The `'Imagick/GD (fallback)'` string is preserved as-is from the legacy
 * code — even though Ghostscript (not GD) actually renders the fallback,
 * Invariant 1 forbids changing this label without controller coordination.
 */
final class PdfConverter
{
    public function __construct(
        private readonly ConvertApiService $convertApi,
        private readonly PdfToPngRendererInterface $pdfRenderer,
        private readonly FileValidator $validator,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     slides_images: list<string>,
     *     slides_count: int,
     *     file_original_path: string,
     *     file_converted_path: string,
     *     conversion_method: string,
     * }
     */
    public function convert(UploadedFile $file, int $chapterId): array
    {
        Log::info('📄 Conversion PDF démarrée', ['file' => $file->getClientOriginalName()]);

        $this->validator->validate($file, ['pdf']);

        $originalPath = $file->store("chapters/{$chapterId}/original", 'public');
        if ($originalPath === false) {
            throw new RuntimeException('Échec sauvegarde du fichier original');
        }
        $fullOriginalPath = storage_path("app/public/{$originalPath}");

        try {
            return $this->tryConvertApi($fullOriginalPath, $originalPath, $chapterId);
        } catch (Exception $convertApiError) {
            Log::warning('⚠️ ConvertAPI échoué, fallback sur Imagick', [
                'error' => $convertApiError->getMessage(),
            ]);

            return $this->fallbackLocalRenderer($fullOriginalPath, $originalPath, $chapterId);
        }
    }

    /**
     * @return array{success: bool, slides_images: list<string>, slides_count: int, file_original_path: string, file_converted_path: string, conversion_method: string}
     */
    private function tryConvertApi(string $fullOriginalPath, string $originalPath, int $chapterId): array
    {
        $pngImages = $this->convertApi->convertPdfToImages(
            $fullOriginalPath,
            "chapters/{$chapterId}/slides",
        );

        Log::info('✓ Conversion ConvertAPI PDF terminée', ['count' => count($pngImages)]);

        return [
            'success'             => true,
            'slides_images'       => array_values($pngImages),
            'slides_count'        => count($pngImages),
            'file_original_path'  => $originalPath,
            'file_converted_path' => $originalPath,
            'conversion_method'   => 'ConvertAPI',
        ];
    }

    /**
     * @return array{success: bool, slides_images: list<string>, slides_count: int, file_original_path: string, file_converted_path: string, conversion_method: string}
     */
    private function fallbackLocalRenderer(string $fullOriginalPath, string $originalPath, int $chapterId): array
    {
        $pngImages = $this->pdfRenderer->render($fullOriginalPath, $chapterId);

        Log::info('✓ Conversion Imagick/GD terminée', ['count' => count($pngImages)]);

        return [
            'success'             => true,
            'slides_images'       => $pngImages,
            'slides_count'        => count($pngImages),
            'file_original_path'  => $originalPath,
            'file_converted_path' => $originalPath,
            'conversion_method'   => 'Imagick/GD (fallback)',
        ];
    }
}
