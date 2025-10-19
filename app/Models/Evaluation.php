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
}
