<?php

declare(strict_types=1);

namespace App\Services\File;

use App\Models\File;
use Illuminate\Contracts\Filesystem\Factory as FilesystemFactory;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * FileMutationService — extrait verbatim de `FileController::{update,destroy,download}`.
 *
 * Responsabilité unique : mutations + download stream sur `App\Models\File`.
 * L'autorisation reste dans le controller (trait `ChecksFileAuthorization` +
 * FormRequests `UpdateFileRequest` / `DeleteFileRequest`) — ce service traite
 * un input déjà autorisé.
 *
 * ## DI strict (§1.6 D)
 *
 * Le disk `local` est résolu via le contrat `Filesystem\Factory` injecté par
 * le container Laravel — pas la Facade `Storage`. Permet aux tests de
 * substituer un fake disk via `Storage::fake('local')` (Laravel patche la
 * Factory).
 *
 * @see PRODUCTION_STANDARDS.md §1.1 + §1.6 D
 * @see app/Http/Controllers/API/FileController.php (caller thin)
 */
final class FileMutationService
{
    public function __construct(
        private readonly FilesystemFactory $filesystem,
    ) {}

    /**
     * Met à jour les métadonnées d'un fichier — appelée par `PUT /api/files/{file}`.
     *
     * Les attributs sont déjà validés par `UpdateFileRequest`. Renvoie le model
     * rafraîchi avec sa relation `user` rechargée (parité avec la version
     * pré-split).
     *
     * @param  array<string, mixed>  $validated
     */
    public function update(File $file, array $validated): File
    {
        $file->update($validated);

        /** @var File $refreshed */
        $refreshed = $file->fresh(['user']);

        return $refreshed;
    }

    /**
     * Soft-delete d'un fichier — appelée par `DELETE /api/files/{file}`.
     * Aucun nettoyage disque (le fichier physique reste restaurable).
     */
    public function destroy(File $file): void
    {
        $file->delete();
    }

    /**
     * Suppression DÉFINITIVE : force-delete + purge du fichier physique.
     * Remplace l'ancien boot hook `File::deleting` (anti-pattern §5) — la
     * purge disque est désormais une décision EXPLICITE du caller.
     */
    public function forceDestroy(File $file): void
    {
        $disk = $this->filesystem->disk('local');

        if ($disk->exists($file->path)) {
            $disk->delete($file->path);
        }

        $file->forceDelete();
    }

    /**
     * Téléchargement streamé d'un fichier — appelée par `GET /api/files/{id}/download`.
     *
     * Incrémente le compteur `downloads_count` (+ `last_downloaded_at`) puis
     * renvoie la `StreamedResponse` Laravel.
     *
     * Renvoie `null` si le fichier physique est introuvable sur le disk — le
     * caller traduit ce cas en 404 (parité avec la version pré-split).
     */
    public function download(File $file): ?StreamedResponse
    {
        if (! $this->filesystem->disk('local')->exists($file->path)) {
            return null;
        }

        // Compteur téléchargements — ex-`File::incrementDownloads` (H2 §5).
        $file->increment('downloads_count');
        $file->update(['last_downloaded_at' => now()]);

        return $this->filesystem->disk('local')->download(
            $file->path,
            $file->original_name,
            ['Content-Type' => $file->mime_type]
        );
    }
}
