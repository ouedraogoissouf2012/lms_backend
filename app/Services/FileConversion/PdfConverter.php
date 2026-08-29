<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

use App\Services\ConvertApiService;
use Exception;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Converts an uploaded `.pdf` to a sequence of PNG slides.
 *
 * Pipeline (mirroring the legacy `FileConversionService::convertPdf`) :
 *  1. Validate the upload (size + extension + MIME) via {@see FileValidator}.
 *  2. Persist the original under `chapters/{chapterId}/original/` on the
 *     **private** disk (#598) via {@see ChapterArtifactStorage}.
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
        private readonly LoggerInterface $logger,
        private readonly ConvertApiService $convertApi,
        private readonly PdfToPngRendererInterface $pdfRenderer,
        private readonly FileValidator $validator,
        private readonly ChapterArtifactStorage $artifacts,
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
        $this->logger->info('📄 Conversion PDF démarrée', ['file' => $file->getClientOriginalName()]);

        $this->validator->validate($file, ['pdf']);

        // #598 — disque PRIVÉ : le document source n'est plus servable via /storage.
        $originalPath = $this->artifacts->storeOriginal($file, $chapterId);
        $fullOriginalPath = $this->artifacts->absolutePath($originalPath);

        try {
            return $this->tryConvertApi($fullOriginalPath, $originalPath, $chapterId);
        } catch (Exception $convertApiError) {
            $this->logger->warning('⚠️ ConvertAPI échoué, fallback sur Imagick', [
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

        $this->logger->info('✓ Conversion ConvertAPI PDF terminée', ['count' => count($pngImages)]);

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

        $this->logger->info('✓ Conversion Imagick/GD terminée', ['count' => count($pngImages)]);

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
