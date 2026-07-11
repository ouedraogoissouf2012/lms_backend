<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Database\Factories\MatiereFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Model Matiere
 *
 * Représente une matière synchronisée depuis KLASSCI.
 *
 * `institution_id` : hérité du trait {@see BelongsToInstitution} (#363,
 * remplace l'annotation locale posée par #258).
 *
 * @property Pivot|null $pivot
 */
class Matiere extends Model
{
    /** @use HasFactory<MatiereFactory> */
    use BelongsToInstitution, HasFactory;

    protected $fillable = [
        'klassci_id',
        'code',
        'libelle',
        'description',
        'coefficient',
        'credit',
        'filiere_id',
        'niveau_id',
        'semestre_id',
        'klassci_data',
        'last_klassci_sync',
        'institution_id',
    ];

    protected $casts = [
        'klassci_data' => 'array',
        'last_klassci_sync' => 'datetime',
    ];

    /**
     * Relation: Classes qui enseignent cette matière
     *
     * @return BelongsToMany<Classe, $this>
     */
    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(Classe::class, 'classe_matiere')
            ->withPivot('enseignant_id', 'coefficient', 'heures_cours', 'heures_td', 'heures_tp')
            ->withTimestamps();
    }

    /**
     * Relation: Cours (Lessons) de cette matière
     *
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * Relation: Topics de forum liés à cette matière
     *
     * @return HasMany<ForumTopic, $this>
     */
    public function forumTopics(): HasMany
    {
        return $this->hasMany(ForumTopic::class);
    }

    /**
     * Vérifie si les données KLASSCI sont à jour (< 24h)
     */
    public function isKlassciDataFresh(): bool
    {
        if (! $this->last_klassci_sync) {
            return false;
        }

        return $this->last_klassci_sync->isAfter(now()->subDay());
    }

    /**
     * Obtenir le nombre total d'heures
     */
    public function getTotalHours(): int
    {
        $pivot = $this->pivot;
        if (! $pivot) {
            return 0;
        }

        return ($pivot->heures_cours ?? 0) +
               ($pivot->heures_td ?? 0) +
               ($pivot->heures_tp ?? 0);
    }
}
