<?php

namespace App\Models;

use App\Models\Concerns\ForumTopicScopes;
use App\Models\Traits\BelongsToInstitution;
use Database\Factories\ForumTopicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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
 * @property Carbon|null $last_activity_at
 */
class ForumTopic extends Model
{
    /** @use HasFactory<ForumTopicFactory> */
    use BelongsToInstitution, ForumTopicScopes, HasFactory, SoftDeletes;

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

    /** @return BelongsTo<User, $this> Auteur (withTrashed #566 : reste affichable si soft-deleted). */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
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

    /**
     * Vérifier si le topic est ouvert (état pur — utilisé par
     * StoreForumPostRequest::authorize).
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
