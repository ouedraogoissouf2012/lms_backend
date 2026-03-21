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
 */
class ForumPost extends Model
{
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
     * Boot du model
     */
    protected static function boot()
    {
        parent::boot();

        // Mettre à jour le compteur de posts du topic après création
        static::created(function ($post) {
            $post->topic->updatePostsCount();
        });

        // Mettre à jour le compteur de posts du topic après suppression
        static::deleted(function ($post) {
            $post->topic->updatePostsCount();
        });
    }

    /**
     * Relation: Topic parent
     */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(ForumTopic::class, 'topic_id');
    }

    /**
     * Relation: Auteur du post
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation: Post parent (si c'est une réponse)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(ForumPost::class, 'parent_id');
    }

    /**
     * Relation: Réponses à ce post
     */
    public function replies(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'parent_id');
    }

    /**
     * Relation: Fichiers attachés au post
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Scope: Posts de niveau racine (pas de parent)
     */
    public function scopeRootLevel($query)
    {
        return $query->whereNull('parent_id');
    }

    /**
     * Scope: Solutions marquées
     */
    public function scopeSolutions($query)
    {
        return $query->where('is_solution', true);
    }

    /**
     * Marquer comme solution
     */
    public function markAsSolution(): bool
    {
        // Retirer le marquage solution des autres posts du même topic
        $this->topic->posts()->where('id', '!=', $this->id)->update(['is_solution' => false]);

        // Marquer ce post comme solution
        $result = $this->update(['is_solution' => true]);

        // Marquer le topic comme résolu
        $this->topic->markAsResolved();

        return $result;
    }

    /**
     * Retirer le marquage solution
     */
    public function unmarkAsSolution(): bool
    {
        $result = $this->update(['is_solution' => false]);

        // Vérifier s'il reste des solutions dans le topic
        if ($this->topic->posts()->where('is_solution', true)->count() === 0) {
            $this->topic->markAsUnresolved();
        }

        return $result;
    }

    /**
     * Marquer comme édité
     */
    public function markAsEdited(): bool
    {
        return $this->update([
            'is_edited' => true,
            'edited_at' => now(),
        ]);
    }

    /**
     * Incrémenter les likes
     */
    public function incrementLikes(): void
    {
        $this->increment('likes_count');
    }

    /**
     * Décrémenter les likes
     */
    public function decrementLikes(): void
    {
        $this->decrement('likes_count');
    }

    /**
     * Vérifier si c'est une réponse
     */
    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /**
     * Vérifier si c'est un post de niveau racine
     */
    public function isRootLevel(): bool
    {
        return $this->parent_id === null;
    }

    /**
     * Obtenir la profondeur du post (niveau d'imbrication)
     */
    public function getDepth(): int
    {
        $depth = 0;
        $parent = $this->parent;

        while ($parent) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }
}
