<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model Quiz
 *
 * Représente un quiz/évaluation en ligne
 *
 * @property int $id
 * @property int $created_by
 * @property int|null $institution_id
 */
class Quiz extends Model
{
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'lesson_id',
        'matiere_id',
        'classe_id',
        'created_by',
        'title',
        'description',
        'instructions',
        'type',
        'duration_minutes',
        'max_attempts',
        'passing_score',
        'shuffle_questions',
        'shuffle_answers',
        'show_correct_answers',
        'allow_review',
        'status',
        'published_at',
        'available_from',
        'available_until',
        'total_questions',
        'total_points',
        'attempts_count',
        'average_score',
        'institution_id',
    ];

    protected $casts = [
        'shuffle_questions' => 'boolean',
        'shuffle_answers' => 'boolean',
        'show_correct_answers' => 'boolean',
        'allow_review' => 'boolean',
        'published_at' => 'datetime',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'passing_score' => 'decimal:2',
        'total_points' => 'decimal:2',
        'average_score' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => 'draft',
        'type' => 'formative',
        'max_attempts' => 1,
        'passing_score' => 50.00,
        'shuffle_questions' => false,
        'shuffle_answers' => false,
        'show_correct_answers' => true,
        'allow_review' => true,
        'total_questions' => 0,
        'total_points' => 0,
        'attempts_count' => 0,
    ];

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
     * Relation: Créateur du quiz
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relation: Questions du quiz
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }

    /**
     * Relation: Tentatives du quiz
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Scope: Quiz publiés
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope: Quiz disponibles (publiés + dans la période de disponibilité)
     */
    public function scopeAvailable($query)
    {
        return $query->published()
            ->where(function ($q) {
                $q->whereNull('available_from')
                  ->orWhere('available_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('available_until')
                  ->orWhere('available_until', '>=', now());
            });
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
     * Scope: Par cours
     */
    public function scopeForLesson($query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }

    /**
     * Vérifier si le quiz est publié
     */
    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->published_at->isPast();
    }

    /**
     * Vérifier si le quiz est disponible maintenant
     */
    public function isAvailable(): bool
    {
        if (!$this->isPublished()) {
            return false;
        }

        // Vérifier available_from
        if ($this->available_from && $this->available_from->isFuture()) {
            return false;
        }

        // Vérifier available_until
        if ($this->available_until && $this->available_until->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Publier le quiz
     */
    public function publish(): bool
    {
        return $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /**
     * Archiver le quiz
     */
    public function archive(): bool
    {
        return $this->update(['status' => 'archived']);
    }

    /**
     * Mettre à jour les statistiques
     */
    public function updateStatistics(): void
    {
        $this->total_questions = $this->questions()->count();
        $this->total_points = $this->questions()->sum('points');
        $this->attempts_count = $this->attempts()->where('status', 'submitted')->count();

        $avgScore = $this->attempts()
            ->where('status', 'submitted')
            ->whereNotNull('score')
            ->avg('score');

        $this->average_score = $avgScore ? round($avgScore, 2) : null;

        $this->save();
    }

    /**
     * Obtenir le nombre de tentatives d'un utilisateur
     */
    public function getAttemptsCountForUser(int $userId): int
    {
        return $this->attempts()
            ->where('user_id', $userId)
            ->where('status', 'submitted')
            ->count();
    }

    /**
     * Vérifier si un utilisateur peut encore tenter le quiz
     */
    public function canUserAttempt(int $userId): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $attemptsCount = $this->getAttemptsCountForUser($userId);
        return $attemptsCount < $this->max_attempts;
    }

    /**
     * Obtenir la meilleure tentative d'un utilisateur
     */
    public function getBestAttemptForUser(int $userId)
    {
        return $this->attempts()
            ->where('user_id', $userId)
            ->where('status', 'submitted')
            ->orderBy('score', 'desc')
            ->first();
    }

    /**
     * Obtenir la dernière tentative d'un utilisateur
     */
    public function getLatestAttemptForUser(int $userId)
    {
        return $this->attempts()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();
    }
}
