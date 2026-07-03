<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

class EvaluationQuestion extends Model
{
    /** @use HasFactory<\Database\Factories\EvaluationQuestionFactory> */
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'evaluation_id',
        'question',
        'type',
        'ordre',
        'points',
        'options',
        'correct_answers',
        'explanation',
        'is_required',
        'institution_id',
    ];

    protected $casts = [
        'points' => 'decimal:2',
        'options' => 'array',
        'correct_answers' => 'array',
        'is_required' => 'boolean',
    ];

    /**
     * Une question appartient à une évaluation
     *
     * @return BelongsTo<Evaluation, $this>
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

}
