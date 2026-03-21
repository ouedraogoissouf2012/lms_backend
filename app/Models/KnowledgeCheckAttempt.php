<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model KnowledgeCheckAttempt
 *
 * Tentative d'un utilisateur sur un quiz "Testez vos connaissances"
 */
class KnowledgeCheckAttempt extends Model
{
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'knowledge_check_id',
        'user_id',
        'score',
        'correct_answers',
        'total_questions',
        'answers',
        'time_spent_seconds',
        'passed',
        'started_at',
        'completed_at',
        'institution_id',
    ];

    protected $casts = [
        'answers' => 'array',
        'passed' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relation: Quiz parent
     */
    public function knowledgeCheck(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCheck::class);
    }

    /**
     * Relation: Utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Duree formatee
     */
    public function getFormattedDurationAttribute(): string
    {
        $seconds = $this->time_spent_seconds;
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes > 0) {
            return "{$minutes}min {$remainingSeconds}s";
        }

        return "{$seconds}s";
    }

    /**
     * Pourcentage de bonnes reponses
     */
    public function getPercentageAttribute(): int
    {
        if ($this->total_questions === 0) {
            return 0;
        }

        return (int) round(($this->correct_answers / $this->total_questions) * 100);
    }

    /**
     * Scope: Par utilisateur
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Reussies
     */
    public function scopePassed($query)
    {
        return $query->where('passed', true);
    }

    /**
     * Scope: Recentes d'abord
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
