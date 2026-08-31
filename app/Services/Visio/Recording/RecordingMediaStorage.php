<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Filesystem\FilesystemAdapter;

/**
 * #469 — **seule autorité** sur le média d'un enregistrement visio : où il vit,
 * et comment il disparaît.
 *
 * ## Pourquoi une classe distincte de `ChapterArtifactStorage`
 *
 * Celle-ci se déclare « seule autorité sur quel artefact **de chapitre** vit sur
 * quel disque ». Y greffer une seconde notion de propriétaire dissoudrait
 * exactement la règle qu'elle existe pour rendre lisible en revue.
 *
 * Et le propriétaire diffère réellement : un `SeanceRecording` peut exister
 * **sans chapitre** — le rattachement échoue légitimement quand la séance ne
 * résout pas une leçon unique (`ambiguous_lesson`, `lesson_not_found`). Ranger
 * le média sous un chapitre inexistant le rendrait inatteignable par toute purge.
 *
 * ## Le disque public, et pourquoi ce n'est pas une régression
 *
 * Même disque que toute vidéo de chapitre : `<video src="/storage/...">` ne
 * s'authentifie pas, et rendre ces fichiers privés casserait la lecture des
 * cours — décision assumée de #598, pas un choix rouvert ici.
 *
 * La différence avec les diapositives (dette #598 : `slide_001.png`, énumérable)
 * est que le nom de fichier vient de `Str::random()` : connaître l'identifiant
 * d'un enregistrement ne permet pas de deviner l'URL de son média.
 *
 * @see \App\Services\FileConversion\ChapterArtifactStorage
 * @see \App\Services\Visio\Recording\SeanceRecordingRetentionService
 */
final class RecordingMediaStorage
{
    /** Même disque que les vidéos de chapitre (cf. #598). */
    public const DISK = 'public';

    public function __construct(
        private readonly FilesystemFactory $filesystem,
    ) {
    }

    /**
     * Copie le média dans le stockage du LMS et renvoie son chemin relatif,
     * ou `null` si la source est illisible.
     *
     * Le LMS devient propriétaire du fichier : c'est ce qui rend son effacement
     * possible. La source, elle, n'est **jamais** supprimée — le fournisseur
     * reste maître de ses propres fichiers, et un import raté doit pouvoir être
     * rejoué.
     */
    public function store(string $absoluteSourcePath, int $recordingId): ?string
    {
        // `is_readable()` plutôt qu'un simple `file_exists()` : un fichier
        // present mais illisible produirait un `stream` vide, donc un media de
        // 0 octet stocke sans la moindre erreur.
        if (! is_readable($absoluteSourcePath) || ! is_file($absoluteSourcePath)) {
            return null;
        }

        $stream = fopen($absoluteSourcePath, 'rb');

        if ($stream === false) {
            return null;
        }

        try {
            $relative = $this->directory($recordingId).'/'.$this->fileName();

            // `writeStream` et non `put` : un enregistrement d'une heure pese
            // plusieurs centaines de Mo, qu'on ne charge pas en memoire.
            return $this->disk()->writeStream($relative, $stream)
                ? $relative
                : null;
        } finally {
            fclose($stream);
        }
    }

    /**
     * URL **absolue** du média.
     *
     * L'absolu n'est pas cosmétique : cette valeur est PERSISTÉE dans
     * `chapters.video_url` et servie telle quelle au front, qui vit sur une
     * autre origine que l'API. Une URL relative y pointerait vers le domaine du
     * front — donc vers rien — et le défaut serait figé en base, pas seulement à
     * l'affichage.
     *
     * Le disque produit déjà de l'absolu en production (`filesystems.public.url`
     * vaut `APP_URL.'/storage'`). Mais `Storage::fake()` reconstruit un disque
     * SANS cette clé et retombe sur du relatif : mesuré, `/storage/...` au lieu
     * de `http://.../storage/...`. On ne laisse donc pas la forme de la valeur
     * dépendre de cette subtilité.
     */
    public function url(string $relativePath): string
    {
        $url = $this->disk()->url($relativePath);

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        $base = config('app.url');

        // Ni disque ni `app.url` exploitables : on rend ce qu'on a plutôt que
        // de fabriquer une URL fausse. Le cas est anormal et se voit — une
        // valeur inventée, non.
        if (! is_string($base) || $base === '') {
            return $url;
        }

        return rtrim($base, '/').'/'.ltrim($url, '/');
    }

    /**
     * Efface **tout** le média de cet enregistrement.
     *
     * Idempotente : purger ce qui n'existe pas n'est pas une erreur, sans quoi
     * toute purge planifiée échouerait sur le premier enregistrement dont le
     * rattachement avait échoué.
     */
    public function purge(int $recordingId): void
    {
        $this->disk()->deleteDirectory($this->directory($recordingId));
    }

    private function directory(int $recordingId): string
    {
        return "recordings/{$recordingId}/video";
    }

    /**
     * Nom aléatoire : l'URL d'un enregistrement ne doit pas se déduire de son
     * identifiant, qui est un entier séquentiel global.
     */
    private function fileName(): string
    {
        return bin2hex(random_bytes(16)).'.mp4';
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = $this->filesystem->disk(self::DISK);

        return $disk;
    }
}
