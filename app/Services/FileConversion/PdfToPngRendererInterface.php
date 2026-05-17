<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

/**
 * Contract for any class that renders a local PDF into a sequence of PNG
 * slides — the operation shared by the PowerPoint fallback (LibreOffice
 * produced the PDF) and the PDF fallback (ConvertAPI failed, render local).
 *
 * Consumers depend on this abstraction so unit tests can replace the
 * concrete {@see PdfToPngRenderer} (which uses Imagick or Ghostscript)
 * with a Mockery double — no PHP extension or external binary required
 * during the test run.
 */
interface PdfToPngRendererInterface
{
    /**
     * Render the PDF into per-page PNG files stored under
     * `chapters/{chapterId}/slides/` on the `public` disk.
     *
     * @return list<string> Relative public-disk paths suitable for DB persistence
     *                     (e.g. `"chapters/12/slides/slide_001.png"`).
     *
     * @throws \RuntimeException If no renderer is available or the conversion fails.
     */
    public function render(string $absolutePdfPath, int $chapterId): array;
}
