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
 * Model ForumTopic
 *
 * Représente un topic de discussion dans le forum
 *
 * @property int $id
 * @property int $user_id
 */
class ForumTopic extends Model
{
    /** @use HasFactory<\Database\Factories\ForumTopicFactory> */
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'user_id',
        'lesson_id',
        'matiere_id',
        'classe_id',
        'title',
        'content',
        'status',
        'is_resolved',
        'views_count',
        'posts_count',
        'last_activity_at',
        'institution_id',
    ];

    protected $casts = [
        'is_resolved' => 'boolean',
        'views_count' => 'integer',
        'posts_count' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'open',
        'is_resolved' => false,
        'views_count' => 0,
        'posts_count' => 0,
    ];

    /**
     * Relation: Auteur du topic
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation: Cours lié (optionnel)
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Relation: Matière liée (optionnel)
     *
     * @return BelongsTo<Matiere, $this>
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * Relation: Classe liée (optionnel)
     *
     * @return BelongsTo<Classe, $this>
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /**
     * Relation: Posts/Réponses
     *
     * @return HasMany<ForumPost, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'topic_id');
    }

    /**
     * Relation: Fichiers attachés au topic
     *
     * @return MorphMany<File, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Scope: Topics ouverts
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumTopic>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumTopic>
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope: Topics épinglés
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumTopic>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumTopic>
     */
    public function scopePinned($query)
    {
        return $query->where('status', 'pinned');
    }

    /**
     * Scope: Topics résolus
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumTopic>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumTopic>
     */
    public function scopeResolved($query)
    {
        return $query->where('is_resolved', true);
    }

    /**
     * Scope: Topics par activité récente
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumTopic>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumTopic>
     */
    public function scopeByActivity($query)
    {
        return $query->orderBy('last_activity_at', 'desc');
    }

    /**
     * Scope: Topics par matière
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumTopic>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumTopic>
     */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /**
     * Scope: Topics par classe
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumTopic>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumTopic>
     */
    public function scopeForClasse($query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    /**
     * Scope: Topics par cours
     *
     * @param  \Illuminate\Database\Eloquent\Builder<ForumTopic>  $query
     * @return \Illuminate\Database\Eloquent\Builder<ForumTopic>
     */
    public function scopeForLesson($query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }

    /**
     * Vérifier si le topic est ouvert (état pur — utilisé par
     * StoreForumPostRequest::authorize).
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
