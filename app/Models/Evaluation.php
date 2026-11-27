<?php

namespace App\Models;

use App\Enums\CorrectionRelease;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\FinDeLEvaluation;
use App\Models\Traits\BelongsToInstitution;
use Database\Factories\EvaluationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evaluation extends Model
{
    /** @use HasFactory<EvaluationFactory> */
    use Auditable, BelongsToInstitution, FinDeLEvaluation, HasFactory, SoftDeletes;

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
        'correction_release',
        'correction_release_at',
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

    /**
     * #1699 : le défaut de la colonne, porté aussi par un modèle neuf qui n'a pas été relu.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'correction_release' => 'with_results',
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
        'correction_release' => CorrectionRelease::class,
        'correction_release_at' => 'datetime',
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
     * #1702 (D3) : les règles de tirage de l'évaluation.
     *
     * @return HasMany<TirageDEvaluation, $this>
     */
    public function tirages(): HasMany
    {
        return $this->hasMany(TirageDEvaluation::class)->orderBy('id');
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
