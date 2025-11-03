<?php

namespace App\Services;

use ConvertApi\ConvertApi;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ConvertApiService
{
    protected $convertApi;

    public function __construct()
    {
        // Essayer config() d'abord, puis env() en fallback
        $secret = config('services.convertapi.secret');

        if (!$secret) {
            $secret = env('CONVERTAPI_SECRET');
        }

        \Illuminate\Support\Facades\Log::info('[ConvertAPI] Initialisation', [
            'secret_present' => $secret ? 'YES' : 'NO',
            'secret_length' => $secret ? strlen($secret) : 0
        ]);

        if (!$secret) {
            throw new \Exception('ConvertAPI secret key not configured. Please set CONVERTAPI_SECRET in .env');
        }

        ConvertApi::setApiCredentials($secret);
        $this->convertApi = new ConvertApi();
    }

    /**
     * Convertit un fichier PowerPoint en images PNG
     *
     * @param string $filePath Chemin du fichier PPTX/PPT
     * @param string $outputDir Dossier de sortie pour les images
     * @return array Liste des chemins des images générées
     */
    public function convertPowerPointToImages(string $filePath, string $outputDir): array
    {
        try {
            Log::info('[ConvertAPI] Début conversion PowerPoint', [
                'file' => $filePath,
                'output_dir' => $outputDir
            ]);

            // Étape 1 : PowerPoint → PDF
            $pdfResult = $this->convertApi->convert(
                'pdf',
                [
                    'File' => $filePath,
                ],
                'pptx'
            );

            Log::info('[ConvertAPI] PowerPoint → PDF OK');

            // Télécharger le PDF temporairement
            $tempPdfPath = storage_path('app/temp/' . uniqid() . '.pdf');
            if (!is_dir(dirname($tempPdfPath))) {
                mkdir(dirname($tempPdfPath), 0755, true);
            }
            $pdfResult->getFile()->save($tempPdfPath);

            // Étape 2 : PDF → PNG (toutes les pages)
            $pngResult = $this->convertApi->convert(
                'png',
                [
                    'File' => $tempPdfPath,
                    'ImageResolution' => '150', // DPI
                    'ScaleImage' => 'true',
                    'ScaleProportions' => 'true',
                ],
                'pdf'
            );

            Log::info('[ConvertAPI] PDF → PNG OK', [
                'pages' => count($pngResult->getFiles())
            ]);

            // Sauvegarder les images
            $images = [];
            $files = $pngResult->getFiles();

            foreach ($files as $index => $file) {
                $imageName = 'slide_' . ($index + 1) . '.png';
                $imagePath = $outputDir . '/' . $imageName;

                // Créer le dossier si nécessaire
                if (!is_dir(storage_path('app/public/' . $outputDir))) {
                    mkdir(storage_path('app/public/' . $outputDir), 0755, true);
                }

                $file->save(storage_path('app/public/' . $imagePath));
                $images[] = $imagePath;
            }

            // Nettoyer le PDF temporaire
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }

            Log::info('[ConvertAPI] Conversion terminée', [
                'images_count' => count($images)
            ]);

            return $images;

        } catch (\Exception $e) {
            Log::error('[ConvertAPI] Erreur conversion PowerPoint', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            throw $e;
        }
    }

    /**
     * Convertit un fichier Word en images PNG
     *
     * @param string $filePath Chemin du fichier DOCX/DOC
     * @param string $outputDir Dossier de sortie pour les images
     * @return array Liste des chemins des images générées
     */
    public function convertWordToImages(string $filePath, string $outputDir): array
    {
        try {
            Log::info('[ConvertAPI] Début conversion Word', [
                'file' => $filePath,
                'output_dir' => $outputDir
            ]);

            // Étape 1 : Word → PDF
            $pdfResult = $this->convertApi->convert(
                'pdf',
                [
                    'File' => $filePath,
                ],
                'docx'
            );

            Log::info('[ConvertAPI] Word → PDF OK');

            // Télécharger le PDF temporairement
            $tempPdfPath = storage_path('app/temp/' . uniqid() . '.pdf');
            if (!is_dir(dirname($tempPdfPath))) {
                mkdir(dirname($tempPdfPath), 0755, true);
            }
            $pdfResult->getFile()->save($tempPdfPath);

            // Étape 2 : PDF → PNG
            $pngResult = $this->convertApi->convert(
                'png',
                [
                    'File' => $tempPdfPath,
                    'ImageResolution' => '150',
                    'ScaleImage' => 'true',
                    'ScaleProportions' => 'true',
                ],
                'pdf'
            );

            Log::info('[ConvertAPI] PDF → PNG OK', [
                'pages' => count($pngResult->getFiles())
            ]);

            // Sauvegarder les images
            $images = [];
            $files = $pngResult->getFiles();

            foreach ($files as $index => $file) {
                $imageName = 'page_' . ($index + 1) . '.png';
                $imagePath = $outputDir . '/' . $imageName;

                if (!is_dir(storage_path('app/public/' . $outputDir))) {
                    mkdir(storage_path('app/public/' . $outputDir), 0755, true);
                }

                $file->save(storage_path('app/public/' . $imagePath));
                $images[] = $imagePath;
            }

            // Nettoyer le PDF temporaire
            if (file_exists($tempPdfPath)) {
                unlink($tempPdfPath);
            }

            Log::info('[ConvertAPI] Conversion Word terminée', [
                'images_count' => count($images)
            ]);

            return $images;

        } catch (\Exception $e) {
            Log::error('[ConvertAPI] Erreur conversion Word', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            throw $e;
        }
    }

    /**
     * Convertit un PDF en images PNG
     *
     * @param string $filePath Chemin du fichier PDF
     * @param string $outputDir Dossier de sortie pour les images
     * @return array Liste des chemins des images générées
     */
    public function convertPdfToImages(string $filePath, string $outputDir): array
    {
        try {
            Log::info('[ConvertAPI] Début conversion PDF', [
                'file' => $filePath,
                'output_dir' => $outputDir
            ]);

            // PDF → PNG direct
            $pngResult = $this->convertApi->convert(
                'png',
                [
                    'File' => $filePath,
                    'ImageResolution' => '150',
                    'ScaleImage' => 'true',
                    'ScaleProportions' => 'true',
                ],
                'pdf'
            );

            Log::info('[ConvertAPI] PDF → PNG OK', [
                'pages' => count($pngResult->getFiles())
            ]);

            // Sauvegarder les images
            $images = [];
            $files = $pngResult->getFiles();

            foreach ($files as $index => $file) {
                $imageName = 'page_' . ($index + 1) . '.png';
                $imagePath = $outputDir . '/' . $imageName;

                if (!is_dir(storage_path('app/public/' . $outputDir))) {
                    mkdir(storage_path('app/public/' . $outputDir), 0755, true);
                }

                $file->save(storage_path('app/public/' . $imagePath));
                $images[] = $imagePath;
            }

            Log::info('[ConvertAPI] Conversion PDF terminée', [
                'images_count' => count($images)
            ]);

            return $images;

        } catch (\Exception $e) {
            Log::error('[ConvertAPI] Erreur conversion PDF', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            throw $e;
        }
    }
}
