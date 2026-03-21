<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model QuizAnswer
 *
 * Réponse possible pour une question de quiz (QCM)
 */
class QuizAnswer extends Model
{
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'question_id',
        'answer_text',
        'is_correct',
        'order',
        'feedback',
        'institution_id',
    ];

    protected $casts = [
        'is_correct' => 'boolean',
    ];

    protected $attributes = [
        'is_correct' => false,
        'order' => 0,
    ];

    /**
     * Relation: Question parente
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }

    /**
     * Scope: Réponses ordonnées
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Scope: Réponses correctes
     */
    public function scopeCorrect($query)
    {
        return $query->where('is_correct', true);
    }
}
