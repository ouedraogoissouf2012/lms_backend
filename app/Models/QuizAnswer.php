<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model QuizAnswer
 *
 * Réponse possible pour une question de quiz (QCM)
 *
 * @property int|null $institution_id
 */
class QuizAnswer extends Model
{
    use BelongsToInstitution;

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
     *
     * @return BelongsTo<QuizQuestion, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(QuizQuestion::class, 'question_id');
    }

    /**
     * Réponses ordonnées. Ordre total (#963, sur le modèle de Lesson, #937) :
     * `id` départage deux réponses au même rang.
     *
     * @param  Builder<QuizAnswer>  $query
     * @return Builder<QuizAnswer>
     */
    public function scopeOrdered(Builder $query)
    {
        return $query->orderBy($query->qualifyColumn('order'))
            ->orderBy($query->qualifyColumn('id'));
    }

    /**
     * Scope: Réponses correctes
     *
     * @param  Builder<QuizAnswer>  $query
     * @return Builder<QuizAnswer>
     */
    public function scopeCorrect(Builder $query)
    {
        return $query->where('is_correct', true);
    }
}
