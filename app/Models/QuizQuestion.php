<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model QuizQuestion
 *
 * Question d'un quiz
 */
class QuizQuestion extends Model
{
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'quiz_id',
        'question_text',
        'explanation',
        'type',
        'order',
        'points',
        'is_required',
        'metadata',
        'institution_id',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'is_required' => 'boolean',
        'metadata' => 'array',
    ];

    protected $attributes = [
        'type' => 'multiple_choice',
        'order' => 0,
        'points' => 1.00,
        'is_required' => true,
    ];

    /**
     * Boot du model
     */
    protected static function boot()
    {
        parent::boot();

        // Mettre à jour les stats du quiz après création/modification
        static::saved(function ($question) {
            $question->quiz->updateStatistics();
        });

        static::deleted(function ($question) {
            $question->quiz->updateStatistics();
        });
    }

    /**
     * Relation: Quiz parent
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Relation: Réponses possibles (pour QCM)
     */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'question_id');
    }

    /**
     * Scope: Questions ordonnées
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Scope: Questions obligatoires
     */
    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    /**
     * Obtenir les bonnes réponses (pour QCM)
     */
    public function getCorrectAnswers()
    {
        return $this->answers()->where('is_correct', true)->get();
    }

    /**
     * Vérifier si une réponse est correcte — délègue à
     * {@see \App\Services\Quiz\QuizGradingService::checkAnswer} (PERF-04).
     *
     * Retourne `null` pour les types nécessitant une correction manuelle
     * (short_answer, essay) — la signature reste `?bool` malgré le hint.
     */
    public function checkAnswer($userAnswer): ?bool
    {
        return app(\App\Services\Quiz\QuizGradingService::class)->checkAnswer($this, $userAnswer);
    }

    /**
     * Calculer les points obtenus pour une réponse — délègue à
     * {@see \App\Services\Quiz\QuizGradingService::calculatePoints} (PERF-04).
     */
    public function calculatePoints($userAnswer): float
    {
        return app(\App\Services\Quiz\QuizGradingService::class)->calculatePoints($this, $userAnswer);
    }

    /**
     * Vérifier si la question nécessite une correction manuelle
     */
    public function requiresManualGrading(): bool
    {
        return in_array($this->type, ['short_answer', 'essay']);
    }

    /**
     * Obtenir le type de question formaté
     */
    public function getTypeLabel(): string
    {
        return match($this->type) {
            'multiple_choice' => 'QCM (Choix unique)',
            'multiple_response' => 'QCM (Choix multiples)',
            'true_false' => 'Vrai/Faux',
            'short_answer' => 'Réponse courte',
            'essay' => 'Rédaction',
            default => 'Autre',
        };
    }
}
