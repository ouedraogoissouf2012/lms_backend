<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model ForumPost
 *
 * Représente un post/réponse dans un topic de forum
 *
 * @property int $id
 * @property int $user_id
 * @property-read \App\Models\ForumTopic|null $topic
 */
class ForumPost extends Model
{
    /** @use HasFactory<\Database\Factories\ForumPostFactory> */
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'topic_id',
        'user_id',
        'parent_id',
        'content',
        'is_solution',
        'is_edited',
        'edited_at',
        'likes_count',
        'institution_id',
    ];

    protected $casts = [
        'is_solution' => 'boolean',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
        'likes_count' => 'integer',
    ];

    protected $attributes = [
        'is_solution' => false,
        'is_edited' => false,
        'likes_count' => 0,
    ];

    /**
     * Relation: Topic parent
     *
     * @return BelongsTo<ForumTopic, $this>
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(ForumTopic::class, 'topic_id');
    }

    /**
     * Relation: Auteur du post
     *
     * `withTrashed()` (#566) : un post est un dossier PRÉSERVÉ ; son auteur reste
     * affichable même soft-deleted (sinon `$post->user` serait null → 500 côté
     * dashboard/notifications).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Relation: Post parent (si c'est une réponse)
     *
     * @return BelongsTo<ForumPost, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'parent_id');
    }

    /**
     * Relation: Réponses à ce post
     *
     * @return HasMany<ForumPost, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'parent_id');
    }

    /**
     * Relation: Fichiers attachés au post
     *
     * @return MorphMany<File, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Scope: Posts de niveau racine (pas de parent)
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumPost>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumPost>
     */
    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: Solutions marquées
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumPost>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumPost>
     */
    public function scopeSolutions($query)
    {
        return $query->where('is_solution', true);
    }

}
