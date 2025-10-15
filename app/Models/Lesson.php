<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Model Lesson
 *
 * Représente un cours/leçon dans le LMS
 */
class Lesson extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'matiere_id',
        'classe_id',
        'enseignant_id',
        'title',
        'description',
        'content',
        'type',
        'status',
        'order',
        'duration_minutes',
        'published_at',
        'archived_at',
        'attachments',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
        'attachments' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'type' => 'cours',
        'order' => 0,
    ];

    /**
     * Relation: Matière liée
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * Relation: Classe liée
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /**
     * Relation: Enseignant créateur
     */
    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /**
     * Relation: Progression des étudiants
     */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /**
     * Relation: Fichiers attachés au cours
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Progression pour un utilisateur spécifique
     */
    public function progressForUser(int $userId)
    {
        return $this->progress()->where('user_id', $userId)->first();
    }

    /**
     * Scope: Cours publiés uniquement
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope: Par matière
     */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /**
     * Scope: Par classe
     */
    public function scopeForClasse($query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    /**
     * Scope: Par enseignant
     */
    public function scopeByTeacher($query, int $enseignantId)
    {
        return $query->where('enseignant_id', $enseignantId);
    }

    /**
     * Scope: Ordonné
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    /**
     * Vérifie si le cours est publié
     */
    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /**
     * Vérifie si le cours est un brouillon
     */
    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    /**
     * Vérifie si le cours est archivé
     */
    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    /**
     * Publier le cours
     */
    public function publish(): bool
    {
        return $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /**
     * Archiver le cours
     */
    public function archive(): bool
    {
        return $this->update([
            'status' => 'archived',
            'archived_at' => now(),
        ]);
    }

    /**
     * Remettre en brouillon
     */
    public function unpublish(): bool
    {
        return $this->update([
            'status' => 'draft',
            'published_at' => null,
        ]);
    }

    /**
     * Obtenir le taux de complétion moyen
     */
    public function getAverageCompletionRate(): float
    {
        $completedCount = $this->progress()
            ->where('status', 'completed')
            ->count();

        $totalCount = $this->progress()->count();

        return $totalCount > 0 ? ($completedCount / $totalCount) * 100 : 0;
    }

    /**
     * Obtenir le nombre d'étudiants ayant commencé
     */
    public function getStudentsStartedCount(): int
    {
        return $this->progress()
            ->whereIn('status', ['in_progress', 'completed'])
            ->count();
    }

    /**
     * Obtenir le nombre d'étudiants ayant terminé
     */
    public function getStudentsCompletedCount(): int
    {
        return $this->progress()
            ->where('status', 'completed')
            ->count();
    }
}
