<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Database\Factories\KnowledgeCheckFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model KnowledgeCheck
 *
 * Quiz "Testez vos connaissances" integre dans les chapitres
 * ATTENTION: Ceci est SEPARE des Evaluations KLASSCI
 *
 * @property bool $user_passed Attribut posé par KnowledgeCheckCrudService (KnowledgeCheckAccessService::isPassedByUser).
 * @property int|null $user_best_score Attribut posé par KnowledgeCheckCrudService (KnowledgeCheckAccessService::bestScore).
 * @property bool $can_attempt Attribut posé par KnowledgeCheckCrudService (KnowledgeCheckAccessService::canAttempt).
 */
class KnowledgeCheck extends Model
{
    /** @use HasFactory<KnowledgeCheckFactory> */
    use BelongsToInstitution, HasFactory, SoftDeletes;

    protected $fillable = [
        'chapter_id',
        'title',
        'description',
        'questions',
        'passing_score',
        'max_attempts',
        'shuffle_questions',
        'shuffle_options',
        'show_correct_answers',
        'show_explanation',
        'time_limit_minutes',
        'position',
        'is_active',
        'is_required', // Quiz obligatoire pour passer au chapitre suivant
        'institution_id',
    ];

    protected $casts = [
        'questions' => 'array',
        'shuffle_questions' => 'boolean',
        'shuffle_options' => 'boolean',
        'show_correct_answers' => 'boolean',
        'show_explanation' => 'boolean',
        'is_active' => 'boolean',
        'is_required' => 'boolean',
    ];

    protected $attributes = [
        'passing_score' => 70,
        'shuffle_questions' => false,
        'shuffle_options' => false,
        'show_correct_answers' => true,
        'show_explanation' => true,
        'is_active' => true,
        'is_required' => false, // Par defaut, le quiz n'est pas obligatoire
        'position' => 0,
    ];

    /**
     * Relation: Chapitre parent
     *
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter(): BelongsTo
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Relation: Tentatives des utilisateurs
     *
     * @return HasMany<KnowledgeCheckAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(KnowledgeCheckAttempt::class);
    }

    /**
     * Nombre de questions
     */
    public function getQuestionsCountAttribute(): int
    {
        return count($this->questions);
    }

    /**
     * Scope: Quiz actifs
     *
     * @param  Builder<KnowledgeCheck>  $query
     * @return Builder<KnowledgeCheck>
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Par chapitre
     *
     * @param  Builder<KnowledgeCheck>  $query
     * @return Builder<KnowledgeCheck>
     */
    public function scopeForChapter($query, int $chapterId)
    {
        return $query->where('chapter_id', $chapterId);
    }

    /**
     * Scope: Ordonne par position
     *
     * @param  Builder<KnowledgeCheck>  $query
     * @return Builder<KnowledgeCheck>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('created_at');
    }
}
