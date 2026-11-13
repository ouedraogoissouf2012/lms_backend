<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\BelongsToInstitution;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\FinDeLEvaluation;

class Evaluation extends Model
{
    /** @use HasFactory<\Database\Factories\EvaluationFactory> */
    use HasFactory, SoftDeletes, BelongsToInstitution, Auditable, FinDeLEvaluation;

    protected $fillable = [
        'klassci_evaluation_id',
        'klassci_matiere_id',
        'matiere_id',
        'matiere_nom',
        'klassci_classe_id',
        'classe_id',
        'classe_nom',
        'klassci_enseignant_id',
        'enseignant_id',
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
        'institution_id',
        'deadline_at',
        'enseignant_nom',
    ];

    /**
     * `klassci_link_guard` est une colonne GÉNÉRÉE par la base (#541) : simple
     * support technique de l'unicité partielle du lien KLASSCI, elle n'a aucun
     * sens pour un client d'API et ne doit pas apparaître dans les payloads —
     * l'évaluation étant sérialisée telle quelle (`EvaluationCreationService`
     * retourne `'data' => $evaluation`).
     *
     * @var list<string>
     */
    protected $hidden = [
        'klassci_link_guard',
    ];

    protected $casts = [
        // D72 : les identifiants locaux, comparés par `===` dans la propriété
        // (ChecksEvaluationOwnership) ; entiers quel que soit le pilote.
        'classe_id' => 'integer',
        'matiere_id' => 'integer',
        'enseignant_id' => 'integer',
        'date_evaluation' => 'datetime',
        'deadline_at' => 'datetime',
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
     *
     * @return HasMany<EvaluationQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(EvaluationQuestion::class)->orderBy('ordre');
    }

    /**
     * Une évaluation a plusieurs soumissions (étudiants)
     *
     * @return HasMany<EvaluationSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(EvaluationSubmission::class);
    }
}
