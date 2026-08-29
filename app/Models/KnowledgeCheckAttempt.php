<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model KnowledgeCheckAttempt
 *
 * Tentative d'un utilisateur sur un quiz "Testez vos connaissances"
 */
class KnowledgeCheckAttempt extends Model
{
    use BelongsToInstitution;

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
     *
     * @return BelongsTo<KnowledgeCheck, $this>
     */
    public function knowledgeCheck(): BelongsTo
    {
        return $this->belongsTo(KnowledgeCheck::class);
    }

    /**
     * Relation: Utilisateur
     *
     * @return BelongsTo<User, $this>
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
     *
     * @param  Builder<KnowledgeCheckAttempt>  $query
     * @return Builder<KnowledgeCheckAttempt>
     */
    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Reussies
     *
     * @param  Builder<KnowledgeCheckAttempt>  $query
     * @return Builder<KnowledgeCheckAttempt>
     */
    public function scopePassed($query)
    {
        return $query->where('passed', true);
    }

    /**
     * Scope: Recentes d'abord
     *
     * @param  Builder<KnowledgeCheckAttempt>  $query
     * @return Builder<KnowledgeCheckAttempt>
     */
    public function scopeLatest($query)
    {
        return $query->orderBy('created_at', 'desc');
    }
}
