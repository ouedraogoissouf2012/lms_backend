<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\BelongsToInstitution;

/**
 * Quiz en ligne. Logique métier dans QuizAccessService, QuizGradingService,
 * QuizStatisticsService (§5 : relations/casts/scopes only).
 *
 * @property int $id
 * @property int $created_by
 * @property int $user_attempts_count            Attribut posé par QuizCrudService (QuizAccessService::attemptsCountForUser).
 * @property bool $user_can_attempt              Attribut posé par QuizCrudService (QuizAccessService::canUserAttempt).
 * @property QuizAttempt|null $user_best_attempt Attribut posé par QuizCrudService (QuizAccessService::bestAttemptForUser).
 * @property QuizAttempt|null $user_latest_attempt Attribut posé par QuizCrudService (QuizAccessService::latestAttemptForUser).
 */
class Quiz extends Model
{
    /** @use HasFactory<\Database\Factories\QuizFactory> */
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'lesson_id', 'matiere_id', 'classe_id', 'created_by',
        'title', 'description', 'instructions', 'type',
        'duration_minutes', 'max_attempts', 'passing_score',
        'shuffle_questions', 'shuffle_answers', 'show_correct_answers', 'allow_review',
        'status', 'published_at', 'available_from', 'available_until',
        'total_questions', 'total_points', 'attempts_count', 'average_score',
        'institution_id',
    ];

    protected $casts = [
        'shuffle_questions' => 'boolean',
        'shuffle_answers' => 'boolean',
        'show_correct_answers' => 'boolean',
        'allow_review' => 'boolean',
        'published_at' => 'datetime',
        'available_from' => 'datetime',
        'available_until' => 'datetime',
        'passing_score' => 'decimal:2',
        'total_points' => 'decimal:2',
        'average_score' => 'decimal:2',
    ];

    protected $attributes = [
        'status' => 'draft',
        'type' => 'formative',
        'max_attempts' => 1,
        'passing_score' => 50.00,
        'shuffle_questions' => false,
        'shuffle_answers' => false,
        'show_correct_answers' => true,
        'allow_review' => true,
        'total_questions' => 0,
        'total_points' => 0,
        'attempts_count' => 0,
    ];

    /**
     * Relation: Cours lié (optionnel)
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Relation: Matière liée (optionnel)
     *
     * @return BelongsTo<Matiere, $this>
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * Relation: Classe liée (optionnel)
     *
     * @return BelongsTo<Classe, $this>
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /**
     * Relation: Créateur du quiz
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Relation: Questions du quiz
     *
     * @return HasMany<QuizQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }

    /**
     * Relation: Tentatives du quiz
     *
     * @return HasMany<QuizAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class);
    }

    /**
     * Scope: Quiz publiés
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Quiz>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Quiz>
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope: Quiz disponibles (publiés + dans la période de disponibilité)
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Quiz>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Quiz>
     */
    public function scopeAvailable($query)
    {
        return $query->published()
            ->where(function ($q) {
                $q->whereNull('available_from')
                  ->orWhere('available_from', '<=', now());
            })
            ->where(function ($q) {
                $q->whereNull('available_until')
                  ->orWhere('available_until', '>=', now());
            });
    }

    /**
     * Scope: Par matière
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Quiz>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Quiz>
     */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /**
     * Scope: Par classe
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Quiz>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Quiz>
     */
    public function scopeForClasse($query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    /**
     * Scope: Par cours
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Quiz>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Quiz>
     */
    public function scopeForLesson($query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }
}
