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
    /** @use HasFactory<\Database\Factories\QuizQuestionFactory> */
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
     * Relation: Quiz parent
     *
     * @return BelongsTo<Quiz, $this>
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Relation: Réponses possibles (pour QCM)
     *
     * @return HasMany<QuizAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'question_id');
    }

    /**
     * Scope: Questions ordonnées
     *
     * @param  \Illuminate\Database\Eloquent\Builder<QuizQuestion>  $query
     * @return \Illuminate\Database\Eloquent\Builder<QuizQuestion>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Scope: Questions obligatoires
     *
     * @param  \Illuminate\Database\Eloquent\Builder<QuizQuestion>  $query
     * @return \Illuminate\Database\Eloquent\Builder<QuizQuestion>
     */
    public function scopeRequired($query)
    {
        return $query->where('is_required', true);
    }

    /**
     * Obtenir les bonnes réponses (pour QCM).
     *
     * H1 (audit) : utilise la collection `answers` eager-loadée si présente
     * pour éviter le N+1 — sinon lazy-load une fois et retourne la sous-
     * collection filtrée.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, QuizAnswer>
     */
    public function getCorrectAnswers()
    {
        return $this->answers->where('is_correct', true)->values();
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
