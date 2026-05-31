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
     * Boot du model
     */
    protected static function boot()
    {
        parent::boot();

        // Mettre à jour les stats du quiz après soumission
        static::updated(function ($attempt) {
            if ($attempt->status === 'submitted' && $attempt->wasChanged('status')) {
                $attempt->quiz->updateStatistics();
            }
        });
    }

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

    /**
     * Démarrer la tentative
     */
    public function start(): bool
    {
        return $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /**
     * Abandonner la tentative
     */
    public function abandon(): bool
    {
        return $this->update(['status' => 'abandoned']);
    }

    /**
     * Vérifier si la tentative a expiré (temps écoulé)
     */
    public function hasExpired(): bool
    {
        if (!$this->quiz->duration_minutes || !$this->started_at) {
            return false;
        }

        $deadline = $this->started_at->addMinutes($this->quiz->duration_minutes);
        return now()->isAfter($deadline);
    }

    /**
     * Alias pour hasExpired() - Vérifier si le temps est écoulé
     */
    public function isTimeExpired(): bool
    {
        return $this->hasExpired();
    }

    /**
     * Obtenir le temps restant (en secondes)
     */
    public function getTimeRemaining(): ?int
    {
        if (!$this->quiz->duration_minutes || !$this->started_at || $this->status !== 'in_progress') {
            return null;
        }

        $deadline = $this->started_at->addMinutes($this->quiz->duration_minutes);
        $remaining = now()->diffInSeconds($deadline, false);

        return max(0, $remaining);
    }

    /**
     * Formater le temps passé
     */
    public function getFormattedTimeSpent(): string
    {
        if (!$this->time_spent_seconds) {
            return 'N/A';
        }

        $hours = floor($this->time_spent_seconds / 3600);
        $minutes = floor(($this->time_spent_seconds % 3600) / 60);
        $seconds = $this->time_spent_seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm %02ds', $hours, $minutes, $seconds);
        }

        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $seconds);
        }

        return sprintf('%ds', $seconds);
    }
}
