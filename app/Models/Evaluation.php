<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evaluation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'klassci_evaluation_id',
        'klassci_matiere_id',
        'klassci_classe_id',
        'klassci_enseignant_id',
        'titre',
        'description',
        'type',
        'status',
        'date_evaluation',
        'duree_minutes',
        'coefficient',
        'bareme',
        'is_online',
        'allow_retake',
        'max_attempts',
        'shuffle_questions',
        'show_results',
        'is_published',
        'notes_published',
        'is_locked',
        'locked_at',
    ];

    protected $casts = [
        'date_evaluation' => 'datetime',
        'coefficient' => 'decimal:2',
        'bareme' => 'decimal:2',
        'is_online' => 'boolean',
        'allow_retake' => 'boolean',
        'shuffle_questions' => 'boolean',
        'show_results' => 'boolean',
        'is_published' => 'boolean',
        'notes_published' => 'boolean',
        'is_locked' => 'boolean',
        'locked_at' => 'datetime',
    ];

    /**
     * Une évaluation a plusieurs questions
     */
    public function questions(): HasMany
    {
        return $this->hasMany(EvaluationQuestion::class)->orderBy('ordre');
    }

    /**
     * Une évaluation a plusieurs soumissions (étudiants)
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(EvaluationSubmission::class);
    }

    /**
     * Vérifie si l'évaluation est disponible pour les étudiants
     */
    public function isAvailable(): bool
    {
        return $this->is_published &&
               in_array($this->status, ['planifiee', 'en_cours']);
    }

    /**
     * Vérifie si l'évaluation est en cours
     */
    public function isActive(): bool
    {
        return $this->status === 'en_cours';
    }

    /**
     * Vérifie si l'évaluation est verrouillée (ne peut plus être modifiée)
     */
    public function isLocked(): bool
    {
        return $this->is_locked || $this->submissions()->count() > 0;
    }

    /**
     * Vérifie si l'évaluation peut être modifiée par l'enseignant
     */
    public function canBeEdited(): bool
    {
        return !$this->isLocked();
    }

    /**
     * Verrouille l'évaluation
     */
    public function lock(): void
    {
        if (!$this->is_locked) {
            $this->update([
                'is_locked' => true,
                'locked_at' => now(),
            ]);
        }
    }

    /**
     * Publie l'évaluation (la rend visible aux étudiants)
     */
    public function publish(): void
    {
        $this->update([
            'is_published' => true,
            'status' => 'planifiee', // Planifiée = prête à être passée
        ]);
    }

    /**
     * Dépublie l'évaluation (la cache aux étudiants)
     */
    public function unpublish(): void
    {
        $this->update([
            'is_published' => false,
            'status' => 'brouillon',
        ]);
    }

    /**
     * Démarre l'évaluation (change le statut en cours)
     */
    public function start(): void
    {
        if ($this->is_published && $this->status === 'planifiee') {
            $this->update([
                'status' => 'en_cours',
            ]);
        }
    }

    /**
     * Termine l'évaluation
     */
    public function finish(): void
    {
        $this->update([
            'status' => 'terminee',
        ]);
    }
}
