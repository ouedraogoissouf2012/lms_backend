<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

use App\Services\ConvertApiService;
use App\Support\Shell\ShellExecutionException;
use App\Support\Shell\ShellExecutorInterface;
use Exception;
use Illuminate\Http\UploadedFile;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Converts `.pptx` / `.ppt` uploads to a sequence of PNG slides.
 *
 * Pipeline (mirroring the legacy `FileConversionService::convertPowerPoint`) :
 *  1. Validate the upload (size + extension + MIME) via {@see FileValidator}.
 *  2. Persist the original under `chapters/{chapterId}/original/`.
 *  3. Try ConvertAPI (`pptx` → PNG directly). On success, return the result.
 *  4. On ConvertAPI failure, fall back to local LibreOffice headless to produce
 *     an intermediate PDF, then delegate to {@see PdfToPngRenderer} for the
 *     PDF → PNG conversion.
 *
 * Public contract (return shape, `conversion_method` strings, file paths) is
 * strictly preserved against the legacy method to satisfy Invariants 1 and 2.
 */
final class PowerPointConverter
{
    /**
     * Cross-platform candidates for the LibreOffice headless binary.
     * First resolvable entry wins ; cf. legacy `findLibreOfficeCommand()`.
     *
     * @var list<string>
     */
    private const LIBREOFFICE_CANDIDATES = [
        'C:/Program Files/LibreOffice/program/soffice.exe',
        'C:/Program Files (x86)/LibreOffice/program/soffice.exe',
        'C:/Program Files/LibreOffice 7/program/soffice.exe',
        'C:/Program Files/LibreOffice 6/program/soffice.exe',
        'soffice',
        'libreoffice',
        '/usr/bin/soffice',
        '/usr/bin/libreoffice',
        '/usr/local/bin/soffice',
        '/Applications/LibreOffice.app/Contents/MacOS/soffice',
    ];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ShellExecutorInterface $shell,
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
        $this->logger->info('📊 Conversion PowerPoint démarrée', ['file' => $file->getClientOriginalName()]);

        $this->validator->validate($file, ['pptx', 'ppt']);

        $originalPath = $file->store("chapters/{$chapterId}/original", 'public');
        if ($originalPath === false) {
            throw new RuntimeException('Échec sauvegarde du fichier original');
        }
        $fullOriginalPath = storage_path("app/public/{$originalPath}");

        $this->logger->info('✓ Fichier original sauvegardé', ['path' => $originalPath]);

        try {
            return $this->tryConvertApi($fullOriginalPath, $originalPath, $chapterId);
        } catch (Exception $convertApiError) {
            $this->logger->warning('⚠️ ConvertAPI échoué, fallback sur LibreOffice', [
                'error' => $convertApiError->getMessage(),
            ]);

            return $this->fallbackLibreOffice($fullOriginalPath, $originalPath, $chapterId);
        }
    }

    /**
     * @return array{success: bool, slides_images: list<string>, slides_count: int, file_original_path: string, file_converted_path: string, conversion_method: string}
     */
    private function tryConvertApi(string $fullOriginalPath, string $originalPath, int $chapterId): array
    {
        $pngImages = $this->convertApi->convertPowerPointToImages(
            $fullOriginalPath,
            "chapters/{$chapterId}/slides",
        );

        $this->logger->info('✓ Conversion ConvertAPI terminée', ['count' => count($pngImages)]);

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
    private function fallbackLibreOffice(string $fullOriginalPath, string $originalPath, int $chapterId): array
    {
        $pdfPath   = $this->pptxToPdf($fullOriginalPath, $chapterId);
        $pngImages = $this->pdfRenderer->render($pdfPath, $chapterId);

        return [
            'success'             => true,
            'slides_images'       => $pngImages,
            'slides_count'        => count($pngImages),
            'file_original_path'  => $originalPath,
            'file_converted_path' => str_replace(storage_path('app/public/'), '', $pdfPath),
            'conversion_method'   => 'LibreOffice (fallback)',
        ];
    }

    /**
     * Run LibreOffice headless to produce a PDF from a `.pptx`/`.ppt`.
     * Returns the absolute filesystem path of the generated PDF.
     *
     * @throws RuntimeException If LibreOffice is missing or the conversion fails.
     */
    private function pptxToPdf(string $pptxPath, int $chapterId): string
    {
        $soffice = $this->shell->locate('libreoffice', self::LIBREOFFICE_CANDIDATES);

        if ($soffice === null) {
            throw new RuntimeException('LibreOffice non installé sur le serveur');
        }

        $outputDir = storage_path("app/public/chapters/{$chapterId}/pdf");
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        try {
            $this->shell->run([
                $soffice,
                '--headless',
                '--convert-to', 'pdf',
                '--outdir', $outputDir,
                $pptxPath,
            ]);
        } catch (ShellExecutionException $e) {
            $this->logger->error('Erreur LibreOffice', [
                'exit'   => $e->exitCode,
                'stderr' => $e->stderr,
            ]);
            throw new RuntimeException('Échec de la conversion PowerPoint');
        }

        $pdfPath = "{$outputDir}/" . pathinfo($pptxPath, PATHINFO_FILENAME) . '.pdf';
        if (! file_exists($pdfPath)) {
            throw new RuntimeException('PDF non généré au chemin attendu');
        }

        return $pdfPath;
    }
}
