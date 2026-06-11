<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model LessonProgress
 *
 * Suit la progression d'un utilisateur sur un cours
 */
class LessonProgress extends Model
{
    use HasFactory, BelongsToInstitution;

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
        'institution_id',
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

}
