<?php

declare(strict_types=1);

namespace App\Services\File;

use App\Models\File;

/**
 * Attributs de présentation d'un fichier (taille human-readable, URL de
 * téléchargement) — ex-méthodes du modèle `File` (H2 audit, §5).
 *
 * Partagé entre FileQueryService et FileUploadService (DRY).
 */
final class FilePresenter
{
    /** Taille human-readable ("1.5 MB"). */
    public function formattedSize(File $file): string
    {
        $bytes = $file->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /** URL de téléchargement (route nommée). */
    public function downloadUrl(File $file): string
    {
        return route('api.files.download', ['id' => $file->id]);
    }
}
