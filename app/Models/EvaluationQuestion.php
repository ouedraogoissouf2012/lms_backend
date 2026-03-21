<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

class EvaluationQuestion extends Model
{
    use BelongsToInstitution;

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
     */
    public function evaluation(): BelongsTo
    {
        return $this->belongsTo(Evaluation::class);
    }

    /**
     * Vérifie si une réponse est correcte
     */
    public function isCorrectAnswer($answer): bool
    {
        if (!$this->correct_answers) {
            return false;
        }

        // Pour QCM simple
        if ($this->type === 'qcm' || $this->type === 'vrai_faux') {
            return in_array($answer, $this->correct_answers);
        }

        // Pour QCM multiple
        if ($this->type === 'qcm_multiple') {
            if (!is_array($answer)) {
                return false;
            }
            sort($answer);
            $correctAnswers = $this->correct_answers;
            sort($correctAnswers);
            return $answer === $correctAnswers;
        }

        // Pour réponse courte (comparaison insensible à la casse)
        if ($this->type === 'reponse_courte') {
            $normalizedAnswer = strtolower(trim($answer));
            foreach ($this->correct_answers as $correct) {
                if (strtolower(trim($correct)) === $normalizedAnswer) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }
}
