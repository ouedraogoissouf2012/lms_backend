<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model LessonProgress
 *
 * Suit la progression d'un utilisateur sur un cours
 */
class LessonProgress extends Model
{
    use HasFactory;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'user_id',
        'lesson_id',
        'status',
        'progress_percentage',
        'time_spent_minutes',
        'started_at',
        'completed_at',
        'last_accessed_at',
        'notes',
        'rating',
        'feedback',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'progress_percentage' => 'integer',
        'time_spent_minutes' => 'integer',
        'rating' => 'integer',
    ];

    protected $attributes = [
        'status' => 'not_started',
        'progress_percentage' => 0,
        'time_spent_minutes' => 0,
    ];

    /**
     * Relation: Utilisateur
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation: Leçon
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Démarrer la progression
     */
    public function start(): bool
    {
        if ($this->status === 'not_started') {
            return $this->update([
                'status' => 'in_progress',
                'started_at' => now(),
                'last_accessed_at' => now(),
            ]);
        }

        return $this->touch('last_accessed_at');
    }

    /**
     * Mettre à jour la progression
     */
    public function updateProgress(int $percentage, int $timeSpentMinutes = null): bool
    {
        $data = [
            'progress_percentage' => min(100, max(0, $percentage)),
            'last_accessed_at' => now(),
        ];

        if ($this->status === 'not_started' && $percentage > 0) {
            $data['status'] = 'in_progress';
            $data['started_at'] = now();
        }

        if ($timeSpentMinutes !== null) {
            $data['time_spent_minutes'] = $this->time_spent_minutes + $timeSpentMinutes;
        }

        // Auto-complétion si 100%
        if ($percentage >= 100 && $this->status !== 'completed') {
            $data['status'] = 'completed';
            $data['completed_at'] = now();
        }

        return $this->update($data);
    }

    /**
     * Marquer comme complété
     */
    public function complete(): bool
    {
        return $this->update([
            'status' => 'completed',
            'progress_percentage' => 100,
            'completed_at' => now(),
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Réinitialiser la progression
     */
    public function reset(): bool
    {
        return $this->update([
            'status' => 'not_started',
            'progress_percentage' => 0,
            'time_spent_minutes' => 0,
            'started_at' => null,
            'completed_at' => null,
            'last_accessed_at' => null,
        ]);
    }

    /**
     * Ajouter une note
     */
    public function addRating(int $rating, string $feedback = null): bool
    {
        return $this->update([
            'rating' => min(5, max(1, $rating)),
            'feedback' => $feedback,
        ]);
    }

    /**
     * Vérifie si le cours est commencé
     */
    public function isStarted(): bool
    {
        return $this->status !== 'not_started';
    }

    /**
     * Vérifie si le cours est en cours
     */
    public function isInProgress(): bool
    {
        return $this->status === 'in_progress';
    }

    /**
     * Vérifie si le cours est complété
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Obtenir le temps passé formaté
     */
    public function getFormattedTimeSpent(): string
    {
        $hours = floor($this->time_spent_minutes / 60);
        $minutes = $this->time_spent_minutes % 60;

        if ($hours > 0) {
            return "{$hours}h {$minutes}min";
        }

        return "{$minutes}min";
    }
}
