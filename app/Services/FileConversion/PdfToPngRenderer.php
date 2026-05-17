<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

use App\Support\Shell\ShellExecutionException;
use App\Support\Shell\ShellExecutorInterface;
use Imagick;
use RuntimeException;

/**
 * Renders a PDF (already on disk) into a sequence of PNG slides under
 * `storage/app/public/chapters/{chapterId}/slides/`.
 *
 * Shared by `PowerPointConverter` (used after the LibreOffice fallback
 * produced the intermediate PDF) and `PdfConverter` (used after ConvertAPI
 * fails) — extracting the rendering keeps both converters DRY and limits
 * the Imagick / Ghostscript decision to a single, well-tested place.
 *
 * Strategy : prefer Imagick (in-process, no extra binary) ; fall back to
 * Ghostscript via {@see ShellExecutor} when the PHP extension is missing.
 * If neither is available, raise a generic {@see RuntimeException}.
 *
 * The output filename convention (`slide_001.png`, `slide_002.png`, …)
 * is preserved verbatim from the legacy `convertPdfWithImagick` /
 * `convertPdfWithGD` (Invariant 2) so existing slide URLs keep working.
 */
final class PdfToPngRenderer implements PdfToPngRendererInterface
{
    /**
     * Cross-platform candidates for the Ghostscript binary. The first
     * resolvable entry wins. Order matters : absolute paths first (cheap
     * `is_file()` check), bare names last (PATH lookup via `where`/`which`).
     *
     * @var list<string>
     */
    private const GHOSTSCRIPT_CANDIDATES = [
        // Windows — standard install paths, newest-first
        'C:/Program Files/gs/gs10.06.0/bin/gswin64c.exe',
        'C:/Program Files/gs/gs10.04.0/bin/gswin64c.exe',
        'C:/Program Files/gs/gs10.03.1/bin/gswin64c.exe',
        'C:/Program Files/gs/gs10.02.0/bin/gswin64c.exe',
        'C:/Program Files (x86)/gs/gs10.06.0/bin/gswin32c.exe',
        'C:/Program Files (x86)/gs/gs10.04.0/bin/gswin32c.exe',
        'C:/Program Files (x86)/gs/gs10.03.1/bin/gswin32c.exe',
        // PATH lookups
        'gswin64c',
        'gswin32c',
        'gs',
        // Unix conventional locations
        '/usr/bin/gs',
        '/usr/local/bin/gs',
    ];

    private const IMAGICK_RESOLUTION_DPI = 150;
    private const IMAGICK_MAX_WIDTH      = 1920;
    private const IMAGICK_QUALITY        = 90;

    public function __construct(
        private readonly ShellExecutorInterface $shell,
    ) {
    }

    /**
     * Render the PDF into per-page PNG files and return their public-disk
     * relative paths (suitable for DB persistence and URL construction).
     *
     * @return list<string> Relative paths like `"chapters/12/slides/slide_001.png"`.
     *
     * @throws RuntimeException If neither Imagick nor Ghostscript is available,
     *                          or if the underlying conversion fails.
     */
    public function render(string $absolutePdfPath, int $chapterId): array
    {
        $outputDir = storage_path("app/public/chapters/{$chapterId}/slides");
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        if (extension_loaded('imagick')) {
            return $this->renderWithImagick($absolutePdfPath, $outputDir, $chapterId);
        }

        return $this->renderWithGhostscript($absolutePdfPath, $outputDir, $chapterId);
    }

    /**
     * In-process Imagick rendering. Mirrors the legacy `convertPdfWithImagick`
     * settings (150 DPI, max width 1920 px, quality 90) so produced files
     * stay byte-compatible with what the LMS frontend already references.
     *
     * @return list<string>
     */
    private function renderWithImagick(string $pdfPath, string $outputDir, int $chapterId): array
    {
        $imagick = new Imagick();
        $imagick->setResolution(self::IMAGICK_RESOLUTION_DPI, self::IMAGICK_RESOLUTION_DPI);
        $imagick->readImage($pdfPath);

        $relativePaths = [];
        $pageNumber    = 1;

        foreach ($imagick as $page) {
            $page->setImageFormat('png');
            $page->setImageCompression(Imagick::COMPRESSION_JPEG);
            $page->setImageCompressionQuality(self::IMAGICK_QUALITY);

            if ($page->getImageWidth() > self::IMAGICK_MAX_WIDTH) {
                $page->scaleImage(self::IMAGICK_MAX_WIDTH, 0);
            }

            $filename = sprintf('slide_%03d.png', $pageNumber);
            $page->writeImage("{$outputDir}/{$filename}");

            $relativePaths[] = "chapters/{$chapterId}/slides/{$filename}";
            $pageNumber++;
        }

        $imagick->clear();
        $imagick->destroy();

        return $relativePaths;
    }

    /**
     * Ghostscript fallback when Imagick is unavailable.
     *
     * Each argument is passed as a separate argv entry to `ShellExecutor::run()`
     * so the shell never sees a concatenated command line — the `%03d` pattern
     * in `-sOutputFile=` is interpreted by Ghostscript itself, not by a shell.
     *
     * @return list<string>
     *
     * @throws RuntimeException If Ghostscript is not installed or returns an error.
     */
    private function renderWithGhostscript(string $pdfPath, string $outputDir, int $chapterId): array
    {
        $gs = $this->shell->locate('ghostscript', self::GHOSTSCRIPT_CANDIDATES);

        if ($gs === null) {
            throw new RuntimeException('Outil de conversion non installé sur le serveur');
        }

        try {
            $this->shell->run([
                $gs,
                '-dSAFER',
                '-dBATCH',
                '-dNOPAUSE',
                '-sDEVICE=png16m',
                '-r' . self::IMAGICK_RESOLUTION_DPI,
                '-dTextAlphaBits=4',
                '-dGraphicsAlphaBits=4',
                '-sOutputFile=' . $outputDir . '/slide_%03d.png',
                $pdfPath,
            ]);
        } catch (ShellExecutionException) {
            // Detailed diagnostics (command, stderr, exit code) already live
            // on the exception's readonly props for server-side log capture
            // upstream ; the message we surface to the caller is generic.
            throw new RuntimeException('Échec de la conversion PDF');
        }

        $files = glob("{$outputDir}/slide_*.png");
        if ($files === false || $files === []) {
            throw new RuntimeException('Aucune image générée à partir du PDF');
        }
        sort($files);

        return array_map(
            static fn (string $path): string => 'chapters/' . $chapterId . '/slides/' . basename($path),
            $files,
        );
    }
}
