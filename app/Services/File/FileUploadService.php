<?php

declare(strict_types=1);

namespace App\Services\File;

use App\Models\File;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * FileUploadService — extrait verbatim de `FileController::upload`.
 *
 * Responsabilité unique : persister un fichier uploadé (storage + row DB).
 *
 * Validation MIME / taille / `fileable_type` whitelist est déjà appliquée en
 * amont par `UploadFileRequest` (cf. issue #10 IDOR + CRITICAL-05 standardisation
 * 30 MB max). Ce service NE re-valide PAS — il transforme un input déjà sûr.
 *
 * ## Scan antivirus
 *
 * Le statut est figé à `pending` (ne pas mentir `clean`). L'intégration ClamAV /
 * VirusTotal sera traitée dans une issue dédiée — comportement identique à
 * la version pré-split.
 *
 * ## DI strict (§1.6 D)
 *
 * Aucune dépendance constructeur — `UploadedFile::storeAs($dir, $name, 'local')`
 * gère son propre disk via le binding Laravel, et `File::create()` est Eloquent
 * pur. Pas de Facade.
 *
 * @see PRODUCTION_STANDARDS.md §1.1 + §1.6 D
 * @see app/Http/Requests/UploadFileRequest.php (validation amont)
 */
final class FileUploadService
{
    /**
     * Mapping catégorie → sous-dossier de storage `uploads/{subfolder}`.
     */
    private const SUBFOLDER_MAP = [
        'course_material' => 'courses',
        'assignment' => 'assignments',
        'forum_attachment' => 'forum',
        'profile_picture' => 'profiles',
    ];

    /**
     * Persiste le fichier uploadé sur le disque `local` puis crée la row
     * `files` correspondante.
     *
     * @return array{file: File|null, error: string|null}
     *   `file` non-null + `error` null en cas de succès ;
     *   `file` null + `error` string en cas d'échec storage.
     */
    public function store(UploadedFile $uploadedFile, User $uploader, array $payload): array
    {
        $originalName = $uploadedFile->getClientOriginalName();
        $extension = $uploadedFile->getClientOriginalExtension();
        $storedName = Str::uuid() . '.' . $extension;

        $category = $payload['category'] ?? 'general';
        $subfolder = self::SUBFOLDER_MAP[$category] ?? 'general';

        $path = $uploadedFile->storeAs(
            "uploads/{$subfolder}",
            $storedName,
            'local'
        );

        if ($path === false || $path === '') {
            return [
                'file' => null,
                'error' => 'Erreur lors du stockage du fichier',
            ];
        }

        $mimeType = $uploadedFile->getMimeType() ?? 'application/octet-stream';

        $file = File::create([
            'user_id' => $uploader->id,
            'fileable_type' => $payload['fileable_type'] ?? null,
            'fileable_id' => $payload['fileable_id'] ?? null,
            'original_name' => $originalName,
            'stored_name' => $storedName,
            'path' => $path,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'size_bytes' => $uploadedFile->getSize(),
            'type' => File::determineTypeFromMime($mimeType),
            'category' => $category,
            'description' => $payload['description'] ?? null,
            'is_public' => (bool) ($payload['is_public'] ?? false),
            // Le scan antivirus n'est pas encore intégré ; on garde la colonne en `pending`
            // (au lieu de la mensonge `clean`) pour ne pas exposer aux clients/admins l'illusion
            // qu'un scan a été effectué. Intégration ClamAV / VirusTotal à faire dans une issue dédiée.
            'virus_scan_status' => 'pending',
        ]);

        $file->load('user:id,name,email');
        $file->formatted_size = $file->getFormattedSize();
        $file->download_url = $file->getDownloadUrl();

        return [
            'file' => $file,
            'error' => null,
        ];
    }
}
