<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Models\Chapter;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Filesystem\FilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * #824 — signe et sert la vidéo d'un enregistrement, désormais privée.
 *
 * ## L'autorisation se fait à l'ÉMISSION
 *
 * Une balise `<video>` n'envoie pas d'en-tête `Authorization`. La route qui
 * sert le fichier ne peut donc pas être authentifiée, et c'est la signature
 * qui tient lieu de jeton — même raisonnement que `ChapterSlideService` pour
 * `<img>` (#620). Seul un utilisateur autorisé à lire le chapitre en reçoit
 * une, puisque l'URL n'est fabriquée que dans la réponse du chapitre.
 *
 * ## Pourquoi 4 heures et pas 60 minutes
 *
 * Les diapositives se rechargent à la demande ; une vidéo se lit d'un trait.
 * Une signature expirée en cours de lecture couperait le cours. 4 h couvre une
 * séance longue sans laisser un lien fuité valable indéfiniment — ce que le
 * nom de fichier aléatoire, lui, laissait faire.
 *
 * ## Les requêtes de plage sont déléguées, pas réécrites
 *
 * `Storage::response()` rend un `StreamedResponse` sans gestion de `Range` :
 * se déplacer dans une vidéo d'une heure obligerait le lecteur à la
 * retélécharger depuis le début. `BinaryFileResponse::prepare()` implémente
 * déjà `Range`, `206`, `Content-Range`, `Accept-Ranges` et `416`. On lui passe
 * le chemin réel du fichier plutôt que de refaire cette arithmétique.
 *
 * **Contrainte assumée** : cela suppose un disque à chemin réel. C'est le cas
 * de `RecordingMediaStorage::DISK` (pilote `local`). Un passage à S3 devrait
 * repasser par une URL signée du fournisseur, pas par cette classe.
 */
final class RecordingVideoService
{
    /** Assez long pour une séance entière, assez court pour qu'un lien meure. */
    private const TTL_HOURS = 4;

    /**
     * Discriminant : `video_url` porte AUSSI les liens externes saisis par
     * l'enseignant (YouTube, Vimeo). Seuls nos propres fichiers se signent.
     */
    public const PROVIDER = 'jibri';

    public function __construct(
        private readonly FilesystemFactory $filesystem,
        private readonly UrlGenerator $urls,
    ) {}

    /**
     * URL signée temporaire, ou `null` si ce chapitre ne porte pas un de nos
     * enregistrements — auquel cas `video_url` doit traverser intact.
     */
    public function signedUrl(Chapter $chapter): ?string
    {
        if ($this->pathOf($chapter) === null) {
            return null;
        }

        return $this->urls->temporarySignedRoute(
            'chapters.video.show',
            now()->addHours(self::TTL_HOURS),
            ['chapter' => $chapter->id],
        );
    }

    /**
     * Rend le média, ou `null` quand il n'y a rien à servir — le contrôleur en
     * fait un 404, sans distinguer « pas un enregistrement » de « fichier
     * absent » : la signature seule ne doit rien révéler de l'état du disque.
     */
    public function stream(Chapter $chapter): ?BinaryFileResponse
    {
        $path = $this->pathOf($chapter);

        if ($path === null) {
            return null;
        }

        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return null;
        }

        return new BinaryFileResponse($disk->path($path), 200, [
            'Content-Type' => 'video/mp4',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function pathOf(Chapter $chapter): ?string
    {
        if ($chapter->video_provider !== self::PROVIDER) {
            return null;
        }

        $path = $chapter->video_url;

        return is_string($path) && $path !== '' ? $path : null;
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->filesystem->disk(RecordingMediaStorage::DISK);

        return $disk;
    }
}
