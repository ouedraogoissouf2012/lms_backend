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
 */
class ForumTopic extends Model
{
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
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation: Cours lié (optionnel)
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Relation: Matière liée (optionnel)
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * Relation: Classe liée (optionnel)
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /**
     * Relation: Posts/Réponses
     */
    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'topic_id');
    }

    /**
     * Relation: Fichiers attachés au topic
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Scope: Topics ouverts
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }

    /**
     * Scope: Topics épinglés
     */
    public function scopePinned($query)
    {
        return $query->where('status', 'pinned');
    }

    /**
     * Scope: Topics résolus
     */
    public function scopeResolved($query)
    {
        return $query->where('is_resolved', true);
    }

    /**
     * Scope: Topics par activité récente
     */
    public function scopeByActivity($query)
    {
        return $query->orderBy('last_activity_at', 'desc');
    }

    /**
     * Scope: Topics par matière
     */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /**
     * Scope: Topics par classe
     */
    public function scopeForClasse($query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    /**
     * Scope: Topics par cours
     */
    public function scopeForLesson($query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }

    /**
     * Incrémenter le nombre de vues
     */
    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    /**
     * Mettre à jour le compteur de posts
     */
    public function updatePostsCount(): void
    {
        $this->posts_count = $this->posts()->count();
        $this->last_activity_at = now();
        $this->save();
    }

    /**
     * Fermer le topic
     */
    public function close(): bool
    {
        return $this->update(['status' => 'closed']);
    }

    /**
     * Ouvrir le topic
     */
    public function open(): bool
    {
        return $this->update(['status' => 'open']);
    }

    /**
     * Épingler le topic
     */
    public function pin(): bool
    {
        return $this->update(['status' => 'pinned']);
    }

    /**
     * Désépingler le topic
     */
    public function unpin(): bool
    {
        return $this->update(['status' => 'open']);
    }

    /**
     * Marquer comme résolu
     */
    public function markAsResolved(): bool
    {
        return $this->update(['is_resolved' => true]);
    }

    /**
     * Marquer comme non résolu
     */
    public function markAsUnresolved(): bool
    {
        return $this->update(['is_resolved' => false]);
    }

    /**
     * Vérifier si le topic est ouvert
     */
    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /**
     * Vérifier si le topic est fermé
     */
    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /**
     * Vérifier si le topic est épinglé
     */
    public function isPinned(): bool
    {
        return $this->status === 'pinned';
    }
}
