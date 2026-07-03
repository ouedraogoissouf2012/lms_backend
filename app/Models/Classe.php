<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model Classe
 *
 * Représente une classe synchronisée depuis KLASSCI.
 *
 * `institution_id` : hérité du trait {@see BelongsToInstitution} (#363,
 * remplace l'annotation locale posée par #265).
 */
class Classe extends Model
{
    /** @use HasFactory<\Database\Factories\ClasseFactory> */
    use HasFactory, BelongsToInstitution;

    protected $fillable = [
        'klassci_id',
        'code',
        'libelle',
        'description',
        'effectif',
        'filiere_id',
        'niveau_id',
        'annee_universitaire_id',
        'klassci_data',
        'last_klassci_sync',
        'institution_id',
    ];

    protected $casts = [
        'klassci_data' => 'array',
        'last_klassci_sync' => 'datetime',
    ];

    /**
     * Relation: Matières enseignées dans cette classe
     *
     * @return BelongsToMany<Matiere, $this>
     */
    public function matieres(): BelongsToMany
    {
        return $this->belongsToMany(Matiere::class, 'classe_matiere')
            ->withPivot('enseignant_id', 'coefficient', 'heures_cours', 'heures_td', 'heures_tp')
            ->withTimestamps();
    }

    /**
     * Relation: Étudiants inscrits dans cette classe
     *
     * @return BelongsToMany<User, $this>
     */
    public function etudiants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'classe_etudiant')
            ->withPivot('date_inscription', 'statut', 'annee_universitaire_id')
            ->withTimestamps();
    }

    /**
     * Relation: Étudiants actifs uniquement
     *
     * @return BelongsToMany<User, $this>
     */
    public function etudiantsActifs(): BelongsToMany
    {
        return $this->etudiants()->wherePivot('statut', 'actif');
    }

    /**
     * Relation: Cours (Lessons) de cette classe
     *
     * @return HasMany<Lesson, $this>
     */
    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class);
    }

    /**
     * Relation: Topics de forum liés à cette classe
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
        if (!$this->last_klassci_sync) {
            return false;
        }

        return $this->last_klassci_sync->isAfter(now()->subDay());
    }

    /**
     * Obtenir l'effectif actuel (étudiants actifs)
     */
    public function getEffectifActuel(): int
    {
        return $this->etudiantsActifs()->count();
    }

    /**
     * Vérifie si la classe est complète
     */
    public function isComplete(): bool
    {
        return $this->getEffectifActuel() >= $this->effectif;
    }
}
