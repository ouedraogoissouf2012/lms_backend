<?php

declare(strict_types=1);

namespace App\Services\FileConversion;

use App\Support\Shell\ShellExecutionException;
use App\Support\Shell\ShellExecutorInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Converts `.docx` / `.doc` uploads to inline HTML via LibreOffice headless.
 *
 * Unlike the PowerPoint and PDF converters there is **no ConvertAPI fallback**
 * because Word's expected output is `content_type = 'text'` (HTML string),
 * whereas `ConvertApiService::convertWordToImages()` produces PNG slides —
 * a different use case that would break the public return structure
 * (Invariant 1). See design.md §"Composant 4 — WordConverter" for the
 * rationale.
 *
 * Public contract (return shape, paths) is preserved verbatim from the
 * legacy `FileConversionService::convertWord`.
 */
final class WordConverter
{
    /**
     * Cross-platform candidates for the LibreOffice headless binary.
     * Duplicated with {@see PowerPointConverter} for now ; could be
     * extracted to a shared config later (not blocking).
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
        private readonly ShellExecutorInterface $shell,
        private readonly FileValidator $validator,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     content: string,
     *     content_type: string,
     *     file_original_path: string,
     * }
     */
    public function convert(UploadedFile $file, int $chapterId): array
    {
        $this->validator->validate($file, ['docx', 'doc']);

        $originalPath = $file->store("chapters/{$chapterId}/original", 'public');
        if ($originalPath === false) {
            throw new RuntimeException('Échec sauvegarde du fichier original');
        }
        $fullOriginalPath = storage_path("app/public/{$originalPath}");

        $htmlPath = $this->docxToHtml($fullOriginalPath, $chapterId);

        $content = file_get_contents($htmlPath);
        if ($content === false) {
            throw new RuntimeException('Impossible de lire le HTML produit');
        }

        return [
            'success'            => true,
            'content'            => $content,
            'content_type'       => 'text',
            'file_original_path' => $originalPath,
        ];
    }

    /**
     * Run LibreOffice headless to produce an HTML rendering of the document.
     * Returns the absolute filesystem path of the generated `.html` file.
     *
     * @throws RuntimeException If LibreOffice is missing or the conversion fails.
     */
    private function docxToHtml(string $docxPath, int $chapterId): string
    {
        $soffice = $this->shell->locate('libreoffice', self::LIBREOFFICE_CANDIDATES);

        if ($soffice === null) {
            throw new RuntimeException('LibreOffice non installé sur le serveur');
        }

        $outputDir = storage_path("app/public/chapters/{$chapterId}/html");
        if (! is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        try {
            $this->shell->run([
                $soffice,
                '--headless',
                '--convert-to', 'html',
                '--outdir', $outputDir,
                $docxPath,
            ]);
        } catch (ShellExecutionException $e) {
            Log::error('Erreur LibreOffice Word', [
                'exit'   => $e->exitCode,
                'stderr' => $e->stderr,
            ]);
            throw new RuntimeException('Échec de la conversion Word');
        }

        $htmlPath = "{$outputDir}/" . pathinfo($docxPath, PATHINFO_FILENAME) . '.html';
        if (! file_exists($htmlPath)) {
            throw new RuntimeException('HTML non généré au chemin attendu');
        }

        return $htmlPath;
    }
}
