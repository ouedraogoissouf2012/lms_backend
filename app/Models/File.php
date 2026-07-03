<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Fichier uploadé (relation polymorphique). Logique métier dans
 * FileQueryService / FileUploadService / FileMutationService (§5).
 *
 * @property int $id
 * @property int $user_id
 * @property bool $is_public
 * @property string $formatted_size Attribut de présentation posé par FileQueryService/FileUploadService (FilePresenter::formattedSize).
 * @property string $download_url   Attribut de présentation posé par FileQueryService/FileUploadService (FilePresenter::downloadUrl).
 */
class File extends Model
{
    /** @use HasFactory<\Database\Factories\FileFactory> */
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
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation polymorphique: Entité parente
     *
     * @return MorphTo<Model, $this>
     */
    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope: Fichiers publics
     *
     * @param  \Illuminate\Database\Eloquent\Builder<File>  $query
     * @return \Illuminate\Database\Eloquent\Builder<File>
     */
    public function scopePublic($query)
    {
        return $query->where('is_public', true);
    }

    /**
     * Scope: Fichiers validés
     *
     * @param  \Illuminate\Database\Eloquent\Builder<File>  $query
     * @return \Illuminate\Database\Eloquent\Builder<File>
     */
    public function scopeValidated($query)
    {
        return $query->where('is_validated', true);
    }

    /**
     * Scope: Fichiers par type
     *
     * @param  \Illuminate\Database\Eloquent\Builder<File>  $query
     * @return \Illuminate\Database\Eloquent\Builder<File>
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope: Fichiers par catégorie
     *
     * @param  \Illuminate\Database\Eloquent\Builder<File>  $query
     * @return \Illuminate\Database\Eloquent\Builder<File>
     */
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

}
