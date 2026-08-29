<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Services\FileConversion\ChapterArtifactStorage;
use App\Services\FileConversionService;
use Illuminate\Http\UploadedFile;
use LogicException;
use Psr\Log\LoggerInterface;

/**
 * Aiguillage de la conversion d un fichier de chapitre vers le bon backend, puis
 * persistance du resultat sur le modele.
 *
 * ## Pourquoi cette classe existe (#598)
 *
 * Extraite de {@see ChapterFileUploadService}, qui vivait a 299 lignes sur une
 * limite de 300 (§1.1). Ajouter la dependance {@see ChapterArtifactStorage} l a
 * fait deborder, et le garde-fou de taille est explicite : « decoupe en
 * collaborateurs DIP plutot que de grossir le fichier ». Comprimer des
 * commentaires aurait rendu la CI verte sans traiter la cause.
 *
 * La coupure suit une vraie frontiere de responsabilite : `ChapterFileUploadService`
 * orchestre l upload (synchrone / asynchrone, suivi de statut) ; cette classe
 * decide **quel convertisseur** appeler et **quoi ecrire** sur le chapitre.
 *
 * Comportement strictement inchange — code deplace verbatim, hors le stockage
 * video qui passe desormais par l autorite unique de disque.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 — Services <= 300 lignes
 * @see PRODUCTION_STANDARDS.md §1.6 D — DI strict
 */
final class ChapterConversionDispatcher
{
    /**
     * Extensions video stockees telles quelles (sans conversion).
     *
     * @var array<int, string>
     */
    private const VIDEO_EXTENSIONS = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv'];

    public function __construct(
        private readonly FileConversionService $fileConversionService,
        private readonly ChapterArtifactStorage $artifacts,
        private readonly LoggerInterface $logger,
    ) {}

    /** Aiguille selon l extension ; une extension inconnue ne fait rien (validee en amont). */
    public function dispatch(Chapter $chapter, UploadedFile $file): void
    {
        $chapterId = $this->chapterId($chapter);
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, ['pptx', 'ppt'], true)) {
            $this->handlePowerPoint($chapter, $file, $chapterId);
        } elseif (in_array($extension, ['docx', 'doc'], true)) {
            $this->handleWord($chapter, $file, $chapterId);
        } elseif ($extension === 'pdf') {
            $this->handlePdf($chapter, $file, $chapterId);
        } elseif (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            $this->handleVideo($chapter, $file, $chapterId);
        }
    }

    private function chapterId(Chapter $chapter): int
    {
        $id = $chapter->getKey();
        if (is_int($id)) {
            return $id;
        }

        if (is_string($id) && ctype_digit($id)) {
            return (int) $id;
        }

        throw new LogicException('Chapter must have a numeric primary key.');
    }

    /** Conversion PowerPoint (pptx/ppt) → images de slides. */
    private function handlePowerPoint(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $result = $this->fileConversionService->convertPowerPoint($file, $chapterId);

        $chapter->update([
            'content_type' => 'powerpoint',
            'file_original_path' => $result['file_original_path'],
            'file_converted_path' => $result['file_converted_path'],
            'slides_images' => $result['slides_images'],
            'slides_count' => $result['slides_count'],
        ]);

        $this->logger->info('PowerPoint converti', ['slides' => $result['slides_count']]);
    }

    /**
     * Conversion Word (docx/doc) → HTML via LibreOffice.
     *
     * #598 — une branche `isset($result['slides_images'])` traînait ici, héritée
     * d'un temps où l'on envisageait un repli ConvertAPI (Word → images). Elle
     * était **morte** : {@see \App\Services\FileConversion\WordConverter} n'a
     * aucun chemin ConvertAPI (son docblock l'explique — la sortie attendue est
     * `content_type: 'text'`, pas des diapositives) et son type de retour ne
     * contient pas cette clé. Une entrée de `phpstan-baseline.neon` la masquait ;
     * l'extraction de cette classe l'a rendue visible, on supprime le code mort
     * plutôt que de déplacer la suppression.
     */
    private function handleWord(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $result = $this->fileConversionService->convertWord($file, $chapterId);

        $chapter->update([
            'content_type' => 'word',
            'content' => $result['content'],
            'file_original_path' => $result['file_original_path'],
        ]);

        $this->logger->info('Word converti en HTML');
    }

    /**
     * Conversion PDF → images de slides (rendu page par page).
     */
    private function handlePdf(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $result = $this->fileConversionService->convertPdf($file, $chapterId);

        $chapter->update([
            'content_type' => 'pdf',
            'file_original_path' => $result['file_original_path'],
            'file_converted_path' => $result['file_converted_path'],
            'slides_images' => $result['slides_images'],
            'slides_count' => $result['slides_count'],
            'pdf_url' => null, // Plus utilisé, on utilise slides_images
        ]);

        $this->logger->info('PDF converti en images', ['pages' => $result['slides_count']]);
    }

    /**
     * Stocke directement le fichier vidéo sans conversion. #598 — la vidéo reste
     * publique, mais le choix du disque est déclaré dans
     * {@see ChapterArtifactStorage::storeVideo()}, jamais recopié ici.
     */
    private function handleVideo(Chapter $chapter, UploadedFile $file, int $chapterId): void
    {
        $videoPath = $this->artifacts->storeVideo($file, $chapterId);

        $chapter->update([
            'content_type' => 'video',
            'file_original_path' => $videoPath,
            'video_url' => "/storage/{$videoPath}",
        ]);

        $this->logger->info('Vidéo stockée', ['path' => $videoPath]);
    }
}
