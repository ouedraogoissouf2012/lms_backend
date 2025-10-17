<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model QuizAttempt
 *
 * Tentative de quiz par un étudiant
 */
class QuizAttempt extends Model
{
    use HasFactory;

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
     * Soumettre la tentative
     */
    public function submit(array $answers): bool
    {
        $this->answers = $answers;
        $this->submitted_at = now();
        $this->status = 'submitted';

        // Calculer le temps passé
        if ($this->started_at) {
            $this->time_spent_seconds = now()->diffInSeconds($this->started_at);
        }

        // Auto-correction pour les questions à correction automatique
        $this->autoGrade();

        return $this->save();
    }

    /**
     * Auto-correction des questions
     */
    public function autoGrade(): void
    {
        $quiz = $this->quiz;
        $questions = $quiz->questions()->with('answers')->get();

        $pointsEarned = 0;
        $pointsPossible = 0;
        $requiresManualGrading = false;

        foreach ($questions as $question) {
            $pointsPossible += $question->points;

            $userAnswer = $this->answers[$question->id] ?? null;

            if ($question->requiresManualGrading()) {
                $requiresManualGrading = true;
                continue;
            }

            if ($userAnswer !== null) {
                $pointsEarned += $question->calculatePoints($userAnswer);
            }
        }

        $this->points_earned = $pointsEarned;
        $this->points_possible = $pointsPossible;

        if (!$requiresManualGrading) {
            // Toutes les questions sont auto-corrigées
            $this->score = $pointsPossible > 0 ? ($pointsEarned / $pointsPossible) * 100 : 0;
            $this->passed = $this->score >= $quiz->passing_score;
            $this->status = 'graded';
        } else {
            // Nécessite correction manuelle
            $this->status = 'submitted';
        }
    }

    /**
     * Correction manuelle par un enseignant
     */
    public function manualGrade(float $pointsEarned, int $gradedBy, ?string $feedback = null): bool
    {
        $this->points_earned = $pointsEarned;
        $this->score = $this->points_possible > 0 ? ($pointsEarned / $this->points_possible) * 100 : 0;
        $this->passed = $this->score >= $this->quiz->passing_score;
        $this->status = 'graded';
        $this->graded_by = $gradedBy;
        $this->graded_at = now();
        $this->teacher_feedback = $feedback;

        return $this->save();
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
