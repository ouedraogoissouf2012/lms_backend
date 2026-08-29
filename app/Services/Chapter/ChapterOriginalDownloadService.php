<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Services\FileConversion\ChapterArtifactStorage;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Issue #598 — sert le **document source** d'un chapitre.
 *
 * Depuis #598, `chapters.file_original_path` désigne un chemin du disque privé
 * (`storage/app/private/`) pour les documents convertis, et du disque public
 * pour les vidéos, qui doivent rester lisibles par `<video>`. Le disque effectif
 * est donc demandé à {@see ChapterArtifactStorage::diskHolding()} et jamais codé
 * en dur — le coder en dur renvoyait 404 en silence sur tout chapitre vidéo
 * (défaut attrapé par l'audit `spec-architect`).
 *
 * Responsabilité unique : transformer un chapitre **déjà autorisé** en réponse
 * streamée, ou `null` si le fichier est absent. L'autorisation n'est PAS ici
 * ({@see \App\Http\Requests\Concerns\ChecksChapterDownloadAuthorization}) : les
 * mélanger rendrait impossible de tester l'une sans l'autre.
 *
 * @see .claude/specs/598-chapter-artifacts-private/design.md §1.2
 */
final class ChapterOriginalDownloadService
{
    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly ChapterArtifactStorage $artifacts,
    ) {
    }

    /**
     * Réponse de téléchargement du document source, ou `null` si le chapitre n'a
     * pas de fichier source exploitable.
     */
    public function download(Chapter $chapter): ?StreamedResponse
    {
        $path = $chapter->file_original_path;

        if (! is_string($path) || ! $this->belongsToChapter($path, $chapter)) {
            return null;
        }

        $disk = $this->artifacts->diskHolding($path);

        if ($disk === null) {
            return null;
        }

        return $this->filesystem->disk($disk)->download($path, $this->downloadName($chapter, $path));
    }

    /**
     * Défense en profondeur : le chemin doit appartenir à l'arborescence de CE
     * chapitre.
     *
     * `file_original_path` n'est aujourd'hui écrit que par le pipeline de
     * conversion — il n'est exposé ni par `StoreChapterRequest` ni par
     * `UpdateChapterRequest`, donc inatteignable par un client. Mais le jour où
     * une écriture future (import, resync KLASSCI, correctif SQL) y placerait une
     * autre valeur, cette route lirait n'importe quel fichier du disque privé :
     * rapports PDF générés, pièces jointes, documents d'autres institutions. Le
     * contrôle coûte une comparaison de préfixe ; son absence coûterait une
     * lecture de fichier arbitraire.
     */
    private function belongsToChapter(string $path, Chapter $chapter): bool
    {
        $key = $chapter->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return false;
        }

        return str_starts_with($path, "chapters/{$key}/") && ! str_contains($path, '..');
    }

    /**
     * Nom proposé au navigateur : le titre du chapitre, assaini, suffixé de
     * l'extension réelle. Le nom stocké est un hash Laravel (`store()`) : le
     * servir tel quel donnerait un fichier illisible à l'utilisateur.
     *
     * L'assainissement ne retient que lettres, chiffres, tiret, souligné et
     * espace **ASCII** : tout le reste (séparateurs de chemin, guillemets, sauts
     * de ligne) perturberait l'en-tête `Content-Disposition`. On reste en ASCII
     * imprimable parce qu'un titre écrit dans un script non translittérable
     * rendrait vide le repli de `HeaderUtils::makeDisposition()`, qui lèverait
     * alors une exception — un 500 sur un nom de fichier serait absurde.
     */
    private function downloadName(Chapter $chapter, string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $safeTitle = trim((string) preg_replace('/[^A-Za-z0-9\-_ ]+/', '', $chapter->title));

        if ($safeTitle === '') {
            $safeTitle = 'chapitre';
        }

        return $extension === '' ? $safeTitle : "{$safeTitle}.{$extension}";
    }
}
