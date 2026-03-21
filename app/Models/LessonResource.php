<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model LessonResource
 *
 * Représente une ressource complémentaire d'une leçon (PDF, liens, documents)
 */
class LessonResource extends Model
{
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'lesson_id',
        'title',
        'description',
        'type',
        'file_path',
        'url',
        'mime_type',
        'file_size',
        'order',
        'download_count',
        'institution_id',
    ];

    protected $attributes = [
        'type' => 'pdf',
        'order' => 0,
        'download_count' => 0,
    ];

    /**
     * Relation: Leçon parente
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Scope: Ordonné
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    /**
     * Scope: Par type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Incrémenter le compteur de téléchargements
     */
    public function incrementDownloadCount(): bool
    {
        return $this->increment('download_count');
    }

    /**
     * Obtenir la taille formatée (Ko, Mo, Go)
     */
    public function getFormattedFileSize(): string
    {
        if (!$this->file_size) {
            return 'N/A';
        }

        $bytes = $this->file_size;
        $units = ['o', 'Ko', 'Mo', 'Go'];
        $factor = floor((strlen($bytes) - 1) / 3);

        return sprintf("%.2f", $bytes / pow(1024, $factor)) . ' ' . $units[$factor];
    }

    /**
     * Vérifier si c'est un fichier local
     */
    public function isLocalFile(): bool
    {
        return !empty($this->file_path);
    }

    /**
     * Vérifier si c'est un lien externe
     */
    public function isExternalLink(): bool
    {
        return !empty($this->url);
    }
}
