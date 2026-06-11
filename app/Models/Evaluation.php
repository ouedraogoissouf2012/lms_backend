<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Traits\BelongsToInstitution;

class Evaluation extends Model
{
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'klassci_evaluation_id',
        'klassci_matiere_id',
        'matiere_nom',
        'klassci_classe_id',
        'classe_nom',
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
        'institution_id',
        'deadline_at',
        'enseignant_nom',
    ];

    protected $casts = [
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
     * Retourne le statut EFFECTIF de l'évaluation
     * Logique centralisée et réutilisable
     *
     * Règles métier:
     * - Si date passée ET (date + durée) passée → 'terminee'
     * - Sinon, retourne le status actuel de la BDD
     */
    public function getEffectiveStatus(): string
    {
        // Si le status est déjà 'terminee', on garde
        if ($this->status === 'terminee') {
            return 'terminee';
        }

        // Calculer la date de fin (date_evaluation + durée)
        $dateFin = $this->date_evaluation->copy()->addMinutes($this->duree_minutes);

        // Si la date de fin est passée, l'évaluation est terminée
        if (now()->greaterThan($dateFin)) {
            return 'terminee';
        }

        // Sinon, retourner le status actuel
        return $this->status;
    }

    /**
     * Vérifie si l'évaluation est réellement terminée
     * Utilise getEffectiveStatus() pour la logique
     */
    public function isTerminee(): bool
    {
        return $this->getEffectiveStatus() === 'terminee';
    }

}
