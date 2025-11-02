<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\UploadedFile;
use Exception;

/**
 * Service de conversion de fichiers (PowerPoint, Word, Excel)
 *
 * Conversions supportées:
 * - PowerPoint (.pptx) → PDF → Images PNG
 * - Word (.docx) → HTML/Markdown
 * - Excel (.xlsx) → JSON/HTML
 * - PDF → Images PNG
 */
class FileConversionService
{
    /**
     * Taille max upload: 30 MB
     */
    const MAX_FILE_SIZE = 30 * 1024 * 1024; // 30 MB

    /**
     * Formats supportés
     */
    const SUPPORTED_FORMATS = [
        'pptx' => 'powerpoint',
        'ppt' => 'powerpoint',
        'docx' => 'word',
        'doc' => 'word',
        'xlsx' => 'excel',
        'xls' => 'excel',
        'pdf' => 'pdf',
    ];

    /**
     * Convertir PowerPoint en images PNG
     *
     * Pipeline: .pptx → PDF → PNG images
     *
     * @param UploadedFile $file
     * @param int $chapterId
     * @return array { slides_images: [], slides_count: int, file_paths: {} }
     */
    public function convertPowerPoint(UploadedFile $file, int $chapterId): array
    {
        try {
            Log::info("📊 Conversion PowerPoint démarrée", ['file' => $file->getClientOriginalName()]);

            // 1. Valider le fichier
            $this->validateFile($file, ['pptx', 'ppt']);

            // 2. Sauvegarder le fichier original
            $originalPath = $file->store("chapters/{$chapterId}/original", 'public');
            $fullOriginalPath = storage_path("app/public/{$originalPath}");

            Log::info("✓ Fichier original sauvegardé", ['path' => $originalPath]);

            // 3. Convertir PowerPoint → PDF avec LibreOffice
            $pdfPath = $this->convertPptxToPdf($fullOriginalPath, $chapterId);

            Log::info("✓ Conversion PDF terminée", ['pdf' => $pdfPath]);

            // 4. Convertir PDF → PNG images avec Imagick
            $pngImages = $this->convertPdfToPngImages($pdfPath, $chapterId);

            Log::info("✓ Conversion PNG terminée", ['count' => count($pngImages)]);

            return [
                'success' => true,
                'slides_images' => $pngImages,
                'slides_count' => count($pngImages),
                'file_original_path' => $originalPath,
                'file_converted_path' => str_replace(storage_path('app/public/'), '', $pdfPath),
            ];
        } catch (Exception $e) {
            Log::error("❌ Erreur conversion PowerPoint", [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);

            throw new Exception("Erreur lors de la conversion PowerPoint: " . $e->getMessage());
        }
    }

    /**
     * Convertir PowerPoint en PDF avec LibreOffice headless
     */
    protected function convertPptxToPdf(string $pptxPath, int $chapterId): string
    {
        // Trouver LibreOffice
        $sofficeCommand = $this->findLibreOfficeCommand();

        if (!$sofficeCommand) {
            throw new Exception("LibreOffice n'est pas installé. Installation requise pour convertir les PowerPoint.");
        }

        $outputDir = storage_path("app/public/chapters/{$chapterId}/pdf");

        // Créer le dossier si nécessaire
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Échapper le chemin si nécessaire
        $sofficeEscaped = (str_contains($sofficeCommand, ' ')) ? '"' . $sofficeCommand . '"' : $sofficeCommand;

        // Commande LibreOffice headless
        $command = sprintf(
            '%s --headless --convert-to pdf --outdir %s %s 2>&1',
            $sofficeEscaped,
            escapeshellarg($outputDir),
            escapeshellarg($pptxPath)
        );

        Log::info("🔄 Exécution LibreOffice", ['cmd' => $command]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("Erreur LibreOffice", ['output' => implode("\n", $output)]);
            throw new Exception("LibreOffice conversion failed. Code: {$returnCode}");
        }

        // Le PDF généré aura le même nom que le fichier original
        $pdfFilename = pathinfo($pptxPath, PATHINFO_FILENAME) . '.pdf';
        $pdfPath = "{$outputDir}/{$pdfFilename}";

        if (!file_exists($pdfPath)) {
            throw new Exception("PDF not generated at expected path: {$pdfPath}");
        }

        return $pdfPath;
    }

    /**
     * Trouver la commande LibreOffice disponible sur le système
     */
    protected function findLibreOfficeCommand(): ?string
    {
        // Chemins possibles pour LibreOffice
        $possiblePaths = [
            // Windows - chemins d'installation standards
            'C:/Program Files/LibreOffice/program/soffice.exe',
            'C:/Program Files (x86)/LibreOffice/program/soffice.exe',
            'C:/Program Files/LibreOffice 7/program/soffice.exe',
            'C:/Program Files/LibreOffice 6/program/soffice.exe',
            // Commandes dans le PATH
            'soffice',
            'libreoffice',
            // Linux/Mac
            '/usr/bin/soffice',
            '/usr/bin/libreoffice',
            '/usr/local/bin/soffice',
            '/Applications/LibreOffice.app/Contents/MacOS/soffice',
        ];

        foreach ($possiblePaths as $path) {
            // Si c'est un chemin complet, vérifier si le fichier existe
            if (str_contains($path, '/') || str_contains($path, '\\')) {
                if (file_exists($path)) {
                    Log::info("✓ LibreOffice trouvé", ['path' => $path]);
                    return $path;
                }
            } else {
                // Sinon, vérifier si la commande est dans le PATH
                $test = shell_exec("where $path 2>nul") ?? shell_exec("which $path 2>/dev/null");
                if ($test) {
                    Log::info("✓ LibreOffice trouvé dans PATH", ['cmd' => $path]);
                    return $path;
                }
            }
        }

        Log::warning("⚠️ LibreOffice non trouvé");
        return null;
    }

    /**
     * Convertir PDF en images PNG (détection automatique Imagick/GD)
     */
    protected function convertPdfToPngImages(string $pdfPath, int $chapterId): array
    {
        // Détection automatique de la méthode de conversion
        if (extension_loaded('imagick')) {
            Log::info("✓ Utilisation de Imagick pour la conversion PDF");
            return $this->convertPdfWithImagick($pdfPath, $chapterId);
        } elseif (extension_loaded('gd')) {
            Log::info("⚠️ Imagick non disponible, utilisation de GD + Ghostscript");
            return $this->convertPdfWithGD($pdfPath, $chapterId);
        } else {
            throw new Exception("Aucune extension de traitement d'image disponible (Imagick ou GD requis).");
        }
    }

    /**
     * Convertir PDF en images PNG avec Imagick (Méthode optimale)
     */
    protected function convertPdfWithImagick(string $pdfPath, int $chapterId): array
    {
        $outputDir = storage_path("app/public/chapters/{$chapterId}/slides");

        // Créer le dossier si nécessaire
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $imagick = new \Imagick();
        $imagick->setResolution(150, 150); // DPI pour qualité optimale
        $imagick->readImage($pdfPath);

        $pngImages = [];

        // Parcourir chaque page du PDF
        foreach ($imagick as $index => $page) {
            $page->setImageFormat('png');
            $page->setImageCompression(\Imagick::COMPRESSION_JPEG);
            $page->setImageCompressionQuality(90);

            // Redimensionner si trop grand (max 1920px width)
            $width = $page->getImageWidth();
            if ($width > 1920) {
                $page->scaleImage(1920, 0); // Garde le ratio
            }

            $slideFilename = sprintf('slide_%03d.png', $index + 1);
            $slidePath = "{$outputDir}/{$slideFilename}";

            $page->writeImage($slidePath);

            // Retourner le chemin relatif pour stockage DB
            $pngImages[] = "chapters/{$chapterId}/slides/{$slideFilename}";
        }

        $imagick->clear();
        $imagick->destroy();

        return $pngImages;
    }

    /**
     * Convertir PDF en images PNG avec GD + Ghostscript (Fallback)
     */
    protected function convertPdfWithGD(string $pdfPath, int $chapterId): array
    {
        // Vérifier si Ghostscript est disponible
        $gsCommand = $this->findGhostscriptCommand();

        if (!$gsCommand) {
            throw new Exception("Ghostscript n'est pas installé. Installation requise pour convertir les PDF avec GD.");
        }

        $outputDir = storage_path("app/public/chapters/{$chapterId}/slides");

        // Créer le dossier si nécessaire
        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $pngImages = [];

        // Utiliser Ghostscript pour extraire chaque page en PNG
        // Échapper correctement le chemin avec des guillemets si nécessaire
        $gsCommandEscaped = (str_contains($gsCommand, ' ')) ? '"' . $gsCommand . '"' : $gsCommand;

        // IMPORTANT: Ne pas utiliser escapeshellarg pour le pattern %03d car Ghostscript doit l'interpréter
        // Utiliser des guillemets manuellement pour les chemins avec espaces
        $outputPattern = "\"{$outputDir}/slide_%03d.png\"";
        $pdfPathEscaped = '"' . str_replace('"', '\"', $pdfPath) . '"';

        $command = sprintf(
            '%s -dSAFER -dBATCH -dNOPAUSE -sDEVICE=png16m -r150 -dTextAlphaBits=4 -dGraphicsAlphaBits=4 -sOutputFile=%s %s 2>&1',
            $gsCommandEscaped,
            $outputPattern,
            $pdfPathEscaped
        );

        Log::info("🔄 Exécution Ghostscript", ['cmd' => $command]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("Erreur Ghostscript", ['output' => implode("\n", $output)]);
            throw new Exception("Échec de la conversion PDF avec Ghostscript. Code: {$returnCode}");
        }

        // Lister les fichiers PNG générés
        $files = glob("{$outputDir}/slide_*.png");
        sort($files);

        foreach ($files as $file) {
            $filename = basename($file);
            $pngImages[] = "chapters/{$chapterId}/slides/{$filename}";
        }

        if (empty($pngImages)) {
            throw new Exception("Aucune image générée à partir du PDF");
        }

        Log::info("✓ Conversion GD terminée", ['pages' => count($pngImages)]);

        return $pngImages;
    }

    /**
     * Trouver la commande Ghostscript disponible sur le système
     */
    protected function findGhostscriptCommand(): ?string
    {
        // Chemins possibles pour Ghostscript
        $possiblePaths = [
            // Windows - chemins d'installation standards
            'C:/Program Files/gs/gs10.06.0/bin/gswin64c.exe',
            'C:/Program Files/gs/gs10.04.0/bin/gswin64c.exe',
            'C:/Program Files/gs/gs10.03.1/bin/gswin64c.exe',
            'C:/Program Files/gs/gs10.02.0/bin/gswin64c.exe',
            'C:/Program Files (x86)/gs/gs10.06.0/bin/gswin32c.exe',
            'C:/Program Files (x86)/gs/gs10.04.0/bin/gswin32c.exe',
            'C:/Program Files (x86)/gs/gs10.03.1/bin/gswin32c.exe',
            // Commandes dans le PATH
            'gswin64c',
            'gswin32c',
            'gs',
            // Linux/Mac
            '/usr/bin/gs',
            '/usr/local/bin/gs',
        ];

        foreach ($possiblePaths as $path) {
            // Si c'est un chemin complet, vérifier si le fichier existe
            if (str_contains($path, '/') || str_contains($path, '\\')) {
                if (file_exists($path)) {
                    Log::info("✓ Ghostscript trouvé", ['path' => $path]);
                    return $path;
                }
            } else {
                // Sinon, vérifier si la commande est dans le PATH
                $test = shell_exec("where $path 2>nul") ?? shell_exec("which $path 2>/dev/null");
                if ($test) {
                    Log::info("✓ Ghostscript trouvé dans PATH", ['cmd' => $path]);
                    return $path;
                }
            }
        }

        Log::warning("⚠️ Ghostscript non trouvé");
        return null;
    }

    /**
     * Convertir PDF en images PNG
     *
     * @param UploadedFile $file
     * @param int $chapterId
     * @return array { slides_images: [], slides_count: int, file_paths: {} }
     */
    public function convertPdf(UploadedFile $file, int $chapterId): array
    {
        try {
            Log::info("📄 Conversion PDF démarrée", ['file' => $file->getClientOriginalName()]);

            // 1. Valider le fichier
            $this->validateFile($file, ['pdf']);

            // 2. Sauvegarder le fichier original
            $originalPath = $file->store("chapters/{$chapterId}/original", 'public');
            $fullOriginalPath = storage_path("app/public/{$originalPath}");

            Log::info("✓ Fichier PDF sauvegardé", ['path' => $originalPath]);

            // 3. Convertir PDF → PNG images avec Imagick
            $pngImages = $this->convertPdfToPngImages($fullOriginalPath, $chapterId);

            Log::info("✓ Conversion PDF→PNG terminée", ['count' => count($pngImages)]);

            return [
                'success' => true,
                'slides_images' => $pngImages,
                'slides_count' => count($pngImages),
                'file_original_path' => $originalPath,
                'file_converted_path' => $originalPath,
            ];
        } catch (Exception $e) {
            Log::error("❌ Erreur conversion PDF", [
                'file' => $file->getClientOriginalName(),
                'error' => $e->getMessage()
            ]);

            throw new Exception("Erreur lors de la conversion PDF: " . $e->getMessage());
        }
    }

    /**
     * Convertir Word en HTML
     */
    public function convertWord(UploadedFile $file, int $chapterId): array
    {
        try {
            $this->validateFile($file, ['docx', 'doc']);

            // Sauvegarder original
            $originalPath = $file->store("chapters/{$chapterId}/original", 'public');
            $fullOriginalPath = storage_path("app/public/{$originalPath}");

            // Convertir en HTML avec LibreOffice
            $htmlPath = $this->convertDocxToHtml($fullOriginalPath, $chapterId);

            return [
                'success' => true,
                'content' => file_get_contents($htmlPath),
                'content_type' => 'text',
                'file_original_path' => $originalPath,
            ];
        } catch (Exception $e) {
            Log::error("❌ Erreur conversion Word", ['error' => $e->getMessage()]);
            throw new Exception("Erreur conversion Word: " . $e->getMessage());
        }
    }

    /**
     * Convertir DOCX en HTML
     */
    protected function convertDocxToHtml(string $docxPath, int $chapterId): string
    {
        // Trouver LibreOffice (comme pour PowerPoint)
        $sofficeCommand = $this->findLibreOfficeCommand();

        if (!$sofficeCommand) {
            throw new Exception("LibreOffice n'est pas installé. Installation requise pour convertir les documents Word.");
        }

        $outputDir = storage_path("app/public/chapters/{$chapterId}/html");

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Échapper le chemin si nécessaire
        $sofficeEscaped = (str_contains($sofficeCommand, ' ')) ? '"' . $sofficeCommand . '"' : $sofficeCommand;

        $command = sprintf(
            '%s --headless --convert-to html --outdir %s %s 2>&1',
            $sofficeEscaped,
            escapeshellarg($outputDir),
            escapeshellarg($docxPath)
        );

        Log::info("🔄 Exécution LibreOffice Word→HTML", ['cmd' => $command]);

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error("Erreur LibreOffice Word", ['output' => implode("\n", $output)]);
            throw new Exception("LibreOffice HTML conversion failed. Code: {$returnCode}");
        }

        $htmlFilename = pathinfo($docxPath, PATHINFO_FILENAME) . '.html';
        $htmlPath = "{$outputDir}/{$htmlFilename}";

        if (!file_exists($htmlPath)) {
            throw new Exception("HTML not generated at expected path: {$htmlPath}");
        }

        return $htmlPath;
    }

    /**
     * Valider le fichier uploadé
     */
    protected function validateFile(UploadedFile $file, array $allowedExtensions): void
    {
        // Vérifier taille
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            throw new Exception("Fichier trop volumineux. Max: 30 MB");
        }

        // Vérifier extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $allowedExtensions)) {
            throw new Exception("Format non supporté. Formats acceptés: " . implode(', ', $allowedExtensions));
        }

        // Vérifier MIME type
        $mimeType = $file->getMimeType();
        $validMimes = [
            'application/vnd.openxmlformats-officedocument.presentationml.presentation', // pptx
            'application/vnd.ms-powerpoint', // ppt
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // docx
            'application/msword', // doc
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // xlsx
            'application/vnd.ms-excel', // xls
            'application/pdf',
        ];

        if (!in_array($mimeType, $validMimes)) {
            throw new Exception("Type MIME invalide: {$mimeType}");
        }
    }

    /**
     * Supprimer les fichiers convertis d'un chapitre
     */
    public function deleteChapterFiles(int $chapterId): void
    {
        $basePath = "chapters/{$chapterId}";

        Storage::disk('public')->deleteDirectory($basePath);

        Log::info("🗑️ Fichiers chapitre supprimés", ['chapter_id' => $chapterId]);
    }

    /**
     * Obtenir les informations d'un fichier uploadé
     */
    public function getFileInfo(UploadedFile $file): array
    {
        return [
            'original_name' => $file->getClientOriginalName(),
            'extension' => $file->getClientOriginalExtension(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'size_mb' => round($file->getSize() / 1024 / 1024, 2),
        ];
    }
}
