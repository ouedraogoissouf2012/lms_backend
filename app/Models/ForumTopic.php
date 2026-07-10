<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 * @property int|null $lesson_id
 * @property int|null $matiere_id
 * @property int|null $classe_id
 * @property string $status
 * @property bool $is_resolved
 * @property \Illuminate\Support\Carbon|null $last_activity_at
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

    /** @return BelongsTo<User, $this> Auteur du topic. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Lesson, $this> Cours lié (optionnel). */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<Matiere, $this> Matière liée (optionnel). */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /** @return BelongsTo<Classe, $this> Classe liée (optionnel). */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /** @return HasMany<ForumPost, $this> Posts/Réponses. */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'topic_id');
    }

    /** @return MorphMany<File, $this> Fichiers attachés au topic. */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeOpen(Builder $query)
    {
        return $query->where('status', 'open');
    }
    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopePinned(Builder $query)
    {
        return $query->where('status', 'pinned');
    }
    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeResolved(Builder $query)
    {
        return $query->where('is_resolved', true);
    }
    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeByActivity(Builder $query)
    {
        return $query->orderBy('last_activity_at', 'desc');
    }
    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeForMatiere(Builder $query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }
    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeForClasse(Builder $query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }
    /** @param Builder<ForumTopic> $query
     * @return Builder<ForumTopic> */
    public function scopeForLesson(Builder $query, int $lessonId)
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
