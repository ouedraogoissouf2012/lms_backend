<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model QuizQuestion
 *
 * Question d'un quiz
 */
class QuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question_text',
        'explanation',
        'type',
        'order',
        'points',
        'is_required',
        'metadata',
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
     * Vérifier si une réponse est correcte
     */
    public function checkAnswer($userAnswer): bool
    {
        switch ($this->type) {
            case 'multiple_choice':
                // Une seule réponse correcte
                return $this->answers()
                    ->where('id', $userAnswer)
                    ->where('is_correct', true)
                    ->exists();

            case 'multiple_response':
                // Plusieurs réponses correctes
                $correctIds = $this->getCorrectAnswers()->pluck('id')->sort()->values()->toArray();
                $userIds = is_array($userAnswer) ? collect($userAnswer)->sort()->values()->toArray() : [];
                return $correctIds === $userIds;

            case 'true_false':
                // Vrai/Faux
                $correctAnswer = $this->answers()->where('is_correct', true)->first();
                return $correctAnswer && $correctAnswer->id == $userAnswer;

            case 'short_answer':
            case 'essay':
                // Nécessite correction manuelle
                return null;

            default:
                return false;
        }
    }

    /**
     * Calculer les points obtenus pour une réponse
     */
    public function calculatePoints($userAnswer): float
    {
        $isCorrect = $this->checkAnswer($userAnswer);

        if ($isCorrect === null) {
            // Nécessite correction manuelle
            return 0;
        }

        return $isCorrect ? $this->points : 0;
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
