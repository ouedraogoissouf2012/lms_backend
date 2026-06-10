<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model Lesson
 *
 * Représente un cours/leçon dans le LMS
 */
class Lesson extends Model
{
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'matiere_id',
        'classe_id',
        'enseignant_id',
        'title',
        'description',
        'prerequis',
        'niveau_difficulte',
        'objectifs_pedagogiques',
        'duree_estimee_minutes',
        'type',
        'status',
        'order',
        'published_at',
        'archived_at',
        'attachments',
        // Note: content fields moved to chapters
        'institution_id',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'archived_at' => 'datetime',
        'attachments' => 'array',
    ];

    protected $attributes = [
        'status' => 'draft',
        'type' => 'cours',
        'niveau_difficulte' => 'debutant',
        'order' => 0,
    ];

    /** Relation: Matière liée */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /** Relation: Classe liée */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /** Relation: Enseignant créateur */
    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /** Relation: Progression des étudiants */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /** Relation: Fichiers attachés au cours */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Relation: Chapitres de la leçon (NOUVELLE STRUCTURE)
     * Une leçon contient plusieurs chapitres
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order')->orderBy('created_at');
    }

    /** Relation: Ressources complémentaires */
    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class)->ordered();
    }

    /** Scope: Cours publiés uniquement */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** Scope: Par matière */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /** Scope: Par classe */
    public function scopeForClasse($query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    /** Scope: Par enseignant */
    public function scopeByTeacher($query, int $enseignantId)
    {
        return $query->where('enseignant_id', $enseignantId);
    }

    /** Scope: Ordonné */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    /** Vérifie si le cours est publié (état pur, sans DB). */
    public function isPublished(): bool
    {
        return $this->status === 'published'
            && $this->published_at !== null
            && $this->published_at->isPast();
    }
}
