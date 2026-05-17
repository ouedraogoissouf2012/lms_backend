<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model File
 *
 * Gestion des fichiers uploadés avec relation polymorphique
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $institution_id
 * @property bool $is_public
 */
class File extends Model
{
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'user_id',
        'fileable_type',
        'fileable_id',
        'original_name',
        'stored_name',
        'path',
        'mime_type',
        'extension',
        'size_bytes',
        'type',
        'category',
        'description',
        'downloads_count',
        'last_downloaded_at',
        'is_public',
        'is_validated',
        'virus_scan_status',
        'institution_id',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'downloads_count' => 'integer',
        'last_downloaded_at' => 'datetime',
        'is_public' => 'boolean',
        'is_validated' => 'boolean',
    ];

    protected $attributes = [
        'type' => 'document',
        'downloads_count' => 0,
        'is_public' => false,
        'is_validated' => true,
        'virus_scan_status' => 'pending',
    ];

    /**
     * Relation: Utilisateur qui a uploadé
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation polymorphique: Entité parente
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: Fichiers publics
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope: Fichiers validés
     */
    public function scopeValidated($query)
    {
        return $query->where('is_validated', true);
    }

    /**
     * Scope: Fichiers par type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Fichiers par catégorie
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    /**
     * Incrémenter le compteur de téléchargements
     */
    public function incrementDownloads(): void
    {
        $this->increment('downloads_count');
        $this->update(['last_downloaded_at' => now()]);
    }

    /**
     * Obtenir l'URL de téléchargement
     */
    public function getDownloadUrl(): string
    {
        return route('api.files.download', ['id' => $this->id]);
    }

    /**
     * Obtenir la taille formatée (human readable)
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }

    /**
     * Vérifier si le fichier existe physiquement
     */
    public function exists(): bool
    {
        return Storage::disk('local')->exists($this->path);
    }

    /**
     * Supprimer le fichier physique du storage
     */
    public function deleteFile(): bool
    {
        if ($this->exists()) {
            return Storage::disk('local')->delete($this->path);
        }
        return false;
    }

    /**
     * Obtenir le chemin complet du fichier
     */
    public function getFullPath(): string
    {
        return Storage::disk('local')->path($this->path);
    }

    /**
     * Vérifier si c'est une image
     */
    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    /**
     * Vérifier si c'est une vidéo
     */
    public function isVideo(): bool
    {
        return $this->type === 'video';
    }

    /**
     * Vérifier si c'est un document
     */
    public function isDocument(): bool
    {
        return $this->type === 'document';
    }

    /**
     * Déterminer le type de fichier à partir du MIME type
     */
    public static function determineTypeFromMime(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if (str_starts_with($mimeType, 'video/')) {
            return 'video';
        }

        if (str_starts_with($mimeType, 'audio/')) {
            return 'audio';
        }

        $documentMimes = [
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'text/plain',
        ];

        if (in_array($mimeType, $documentMimes)) {
            return 'document';
        }

        return 'other';
    }

    /**
     * Boot du model
     */
    protected static function boot()
    {
        parent::boot();

        // Supprimer le fichier physique lors de la suppression du model
        static::deleting(function ($file) {
            if ($file->isForceDeleting()) {
                $file->deleteFile();
            }
        });
    }
}
