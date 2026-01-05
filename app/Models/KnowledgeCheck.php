<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model KnowledgeCheck
 *
 * Quiz "Testez vos connaissances" integre dans les chapitres
 * ATTENTION: Ceci est SEPARE des Evaluations KLASSCI
 */
class KnowledgeCheck extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'chapter_id',
        'title',
        'description',
        'questions',
        'passing_score',
        'max_attempts',
        'shuffle_questions',
        'shuffle_options',
        'show_correct_answers',
        'show_explanation',
        'time_limit_minutes',
        'position',
        'is_active',
        'is_required', // Quiz obligatoire pour passer au chapitre suivant
    ];

    protected $casts = [
        'questions' => 'array',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'show_correct_answers' => 'boolean',
        'show_explanation' => 'boolean',
        'is_active' => 'boolean',
        'is_required' => 'boolean',
    ];

    protected $attributes = [
        'passing_score' => 70,
        'shuffle_questions' => false,
        'shuffle_options' => false,
        'show_correct_answers' => true,
        'show_explanation' => true,
        'is_active' => true,
        'is_required' => false, // Par defaut, le quiz n'est pas obligatoire
        'position' => 0,
    ];

    /**
     * Relation: Chapitre parent
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Relation: Tentatives des utilisateurs
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(KnowledgeCheckAttempt::class);
    }

    /**
     * Obtenir les tentatives d'un utilisateur specifique
     */
    public function userAttempts(int $userId): HasMany
    {
        return $this->attempts()->where('user_id', $userId);
    }

    /**
     * Verifier si un utilisateur a reussi ce quiz
     */
    public function isPassedByUser(int $userId): bool
    {
        return $this->attempts()
            ->where('user_id', $userId)
            ->where('passed', true)
            ->exists();
    }

    /**
     * Obtenir le meilleur score d'un utilisateur
     */
    public function getBestScore(int $userId): ?int
    {
        return $this->attempts()
            ->where('user_id', $userId)
            ->max('score');
    }

    /**
     * Verifier si un utilisateur peut encore tenter ce quiz
     */
    public function canAttempt(int $userId): bool
    {
        if ($this->max_attempts === null) {
            return true;
        }

        $attemptCount = $this->attempts()
            ->where('user_id', $userId)
            ->count();

        return $attemptCount < $this->max_attempts;
    }

    /**
     * Nombre de questions
     */
    public function getQuestionsCountAttribute(): int
    {
        return is_array($this->questions) ? count($this->questions) : 0;
    }

    /**
     * Scope: Quiz actifs
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Par chapitre
     */
    public function scopeForChapter($query, int $chapterId)
    {
        return $query->where('chapter_id', $chapterId);
    }

    /**
     * Scope: Ordonne par position
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('created_at');
    }
}
