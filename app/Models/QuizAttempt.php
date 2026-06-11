<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model QuizAttempt
 *
 * Tentative de quiz par un étudiant
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $institution_id
 * @property-read \App\Models\Quiz|null $quiz
 */
class QuizAttempt extends Model
{
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'quiz_id',
        'user_id',
        'attempt_number',
        'status',
        'started_at',
        'submitted_at',
        'time_spent_seconds',
        'score',
        'points_earned',
        'points_possible',
        'passed',
        'answers',
        'teacher_feedback',
        'graded_by',
        'graded_at',
        'institution_id',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'submitted_at' => 'datetime',
        'graded_at' => 'datetime',
        'score' => 'decimal:2',
        'points_earned' => 'decimal:2',
        'points_possible' => 'decimal:2',
        'passed' => 'boolean',
        'answers' => 'array',
    ];

    protected $attributes = [
        'status' => 'in_progress',
        'attempt_number' => 1,
    ];

    /**
     * Relation: Quiz
     */
    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Relation: Étudiant
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation: Enseignant qui a noté (optionnel)
     */
    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'graded_by');
    }

    /**
     * Scope: Tentatives soumises
     */
    public function scopeSubmitted($query)
    {
        return $query->where('status', 'submitted');
    }

    /**
     * Scope: Tentatives en cours
     */
    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    /**
     * Scope: Tentatives réussies
     */
    public function scopePassed($query)
    {
        return $query->where('passed', true);
    }

}
