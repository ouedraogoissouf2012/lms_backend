<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\FileConversion\ChapterArtifactStorage;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Issue #598 — **migre** vers le disque privé les artefacts sensibles déjà
 * déposés sur le disque public par l'ancien pipeline de conversion.
 *
 * Le correctif #598 empêche toute NOUVELLE écriture d'un document source, d'un
 * HTML plein-texte ou d'un PDF intermédiaire sur `storage/app/public/`. Il ne
 * fait rien pour ceux qui s'y trouvent déjà : tant qu'ils y sont,
 * `GET /storage/chapters/{id}/original/<hash>.docx` continue de les servir sans
 * authentification.
 *
 *   php artisan chapters:purge-public-artifacts             # simulation (défaut)
 *   php artisan chapters:purge-public-artifacts --apply     # migration réelle
 *
 * ## Migrer, et surtout PAS supprimer
 *
 * Une première version supprimait purement et simplement — l'audit
 * `spec-security` l'a classée HIGH, à raison : pour toute ligne créée avant
 * #598, `chapters.file_original_path` pointe encore vers le chemin **public**.
 * Supprimer aurait donc détruit définitivement le document de l'enseignant, sans
 * que la nouvelle route authentifiée puisse prendre le relais. L'opérateur
 * n'aurait eu aucune option sûre : ne rien faire laissait la fuite ouverte,
 * appliquer détruisait les données.
 *
 * On **copie donc à chemin relatif constant** (`chapters/{id}/{kind}/...`
 * identique sur les deux disques), on vérifie la copie, et on ne supprime la
 * source publique qu'ensuite. Les lignes en base restent valides et sont servies
 * par `GET /api/chapters/{id}/original`.
 *
 * ## Pourquoi une commande et non une migration
 *
 * L'opération porte sur le système de fichiers, pas sur le schéma. Elle doit
 * pouvoir être **simulée** puis **rejouée** (un déploiement peut s'intercaler),
 * ce qu'un `up()` unique ne permet pas. La simulation est le défaut ; `--apply`
 * est explicite.
 *
 * ## Ce qui n'est jamais touché
 *
 * `chapters/{id}/slides/` et `chapters/{id}/video/` — assets publics légitimes.
 *
 * @see app/Services/FileConversion/ChapterArtifactStorage.php
 * @see .claude/specs/598-chapter-artifacts-private/design.md §1.3
 */
final class PurgePublicChapterArtifacts extends Command
{
    protected $signature = 'chapters:purge-public-artifacts
                            {--apply : Migre réellement (sans ce drapeau, la commande se contente de compter)}';

    protected $description = 'Migre vers le disque privé les documents sources, HTML et PDF de chapitres restés publics (#598)';

    public function handle(FilesystemFactory $filesystem): int
    {
        $public = $filesystem->disk(ChapterArtifactStorage::PUBLIC_DISK);
        $private = $filesystem->disk(ChapterArtifactStorage::PRIVATE_DISK);

        $files = $this->sensitiveFiles($public);

        if ($files === []) {
            $this->info('✓ Aucun artefact sensible sur le disque public — rien à migrer.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->warn(sprintf('[SIMULATION] %d fichier(s) sensible(s) seraient migrés vers le disque privé.', count($files)));
            $this->line('Relancer avec --apply pour migrer réellement.');

            foreach ($files as $file) {
                $this->line("  - {$file}");
            }

            return self::SUCCESS;
        }

        return $this->migrate($files, $public, $private);
    }

    /**
     * Fichiers du disque public situés sous un sous-dossier sensible.
     *
     * Les identifiants de chapitre viennent du **listing du disque**, jamais
     * d'une entrée utilisateur, et le suffixe est contraint à `PRIVATE_KINDS` :
     * ni traversée, ni glob, ni possibilité d'atteindre `slides/` ou `video/`.
     *
     * @return list<string>
     */
    private function sensitiveFiles(Filesystem $public): array
    {
        $files = [];

        foreach ($public->directories('chapters') as $chapterDirectory) {
            foreach (ChapterArtifactStorage::PRIVATE_KINDS as $kind) {
                foreach ($public->allFiles("{$chapterDirectory}/{$kind}") as $file) {
                    $files[] = $file;
                }
            }
        }

        return $files;
    }

    /**
     * Copie chaque fichier vers le disque privé **au même chemin relatif**, puis
     * ne supprime la source **qu'après** une écriture confirmée. Un échec laisse
     * le fichier public en place et fait échouer la commande — jamais de
     * suppression non compensée.
     *
     * @param  list<string>  $files
     */
    private function migrate(array $files, Filesystem $public, Filesystem $private): int
    {
        $migrated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $file) {
            if ($private->exists($file)) {
                $public->delete($file);
                $skipped++;

                continue;
            }

            $contents = $public->get($file);

            // La confirmation de la copie est le retour booléen de `put()` : un
            // `exists()` supplémentaire serait redondant (PHPStan le prouve) et
            // laisserait croire à une vérification qui n'en est pas une.
            if ($contents === null || $private->put($file, $contents) === false) {
                $this->error("✗ Migration impossible, fichier laissé en place : {$file}");
                $failed++;

                continue;
            }

            $public->delete($file);
            $migrated++;
        }

        $this->info(sprintf(
            '✓ %d fichier(s) migré(s) vers le disque privé, %d déjà présent(s), %d échec(s) (#598).',
            $migrated,
            $skipped,
            $failed,
        ));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
