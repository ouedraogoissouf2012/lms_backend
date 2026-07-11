<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Progression d'un utilisateur sur un chapitre
 */
class ChapterProgress extends Model
{
    use BelongsToInstitution;

    protected $table = 'chapter_progress';

    protected $fillable = [
        'user_id',
        'chapter_id',
        'completed_at',
        'time_spent_seconds',
        'quiz_score',
        'quiz_passed',
        'institution_id',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'quiz_passed' => 'boolean',
        'time_spent_seconds' => 'integer',
        'quiz_score' => 'integer',
    ];

    /**
     * Relation avec l'utilisateur
     *
     * @return BelongsTo<User, $this>
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec le chapitre
     *
     * @return BelongsTo<Chapter, $this>
     */
    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Verifier si le chapitre est complete (état pur, sans DB).
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
