<?php

declare(strict_types=1);

namespace App\Services\Visio\Recording;

use App\Services\FileConversion\ChapterArtifactStorage;
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
 * ## Le disque est PRIVÉ (#824), et pourquoi l'argument inverse est tombé
 *
 * Ce média vivait sur le disque `public`, servi par le serveur web sans jamais
 * traverser Laravel — le `.htaccess` de `storage/app/public` porte
 * `Require all granted`. Une URL qui fuitait, par un journal, un historique ou
 * un partage, donnait la vidéo d'un cours entier à qui la détenait, sans
 * compte et sans trace.
 *
 * Le choix était défendu ici même par : « `<video src>` ne s'authentifie pas,
 * rendre ces fichiers privés casserait la lecture des cours ». La prémisse est
 * exacte, la conclusion ne l'est plus : une URL **signée et temporaire** porte
 * son autorisation dans son adresse, donc sans en-tête. `ChapterSlideService`
 * le fait déjà pour `<img>` depuis #689 — le motif existait à côté.
 *
 * Le nom de fichier aléatoire reste utile, mais ne protégeait rien à lui seul :
 * il rend l'URL indevinable, pas inaccessible, et une fois connue elle valait
 * pour toujours.
 *
 * @see ChapterArtifactStorage
 * @see SeanceRecordingRetentionService
 */
final class RecordingMediaStorage
{
    /**
     * Disque **privé** : hors de toute racine servie par le serveur web.
     *
     * L'accès passe désormais par une route signée et temporaire (#824), seule
     * manière de donner accès à un `<video>` sans lui demander d'en-tête.
     */
    public const DISK = 'local';

    public function __construct(
        private readonly FilesystemFactory $filesystem,
    ) {}

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
