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
 *
 * @property LessonProgress|null $user_progress Attribut posé par LessonListService (LessonProgressService::progressForUser).
 * @property array{students_started: int, students_completed: int, average_completion_rate: float} $statistics Attribut posé par LessonListService (staff uniquement).
 * @property-read int|null $students_started Alias `withCount(['progress as students_started'])` (DashboardTeacherController).
 */
class Lesson extends Model
{
    /** @use HasFactory<\Database\Factories\LessonFactory> */
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

    /**
     * Relation: Matière liée
     *
     * @return BelongsTo<Matiere, $this>
     */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /**
     * Relation: Classe liée
     *
     * @return BelongsTo<Classe, $this>
     */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /**
     * Relation: Enseignant créateur
     *
     * @return BelongsTo<User, $this>
     */
    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /**
     * Relation: Progression des étudiants
     *
     * @return HasMany<LessonProgress, $this>
     */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /**
     * Relation: Fichiers attachés au cours
     *
     * @return MorphMany<File, $this>
     */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /**
     * Relation: Chapitres de la leçon (NOUVELLE STRUCTURE)
     * Une leçon contient plusieurs chapitres
     *
     * @return HasMany<Chapter, $this>
     */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order')->orderBy('created_at');
    }

    /**
     * Relation: Ressources complémentaires
     *
     * @return HasMany<LessonResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class)->ordered();
    }

    /**
     * Scope: Cours publiés uniquement
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lesson>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Lesson>
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /**
     * Scope: Par matière
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lesson>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Lesson>
     */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /**
     * Scope: Par classe
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lesson>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Lesson>
     */
    public function scopeForClasse($query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }

    /**
     * Scope: Par enseignant
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lesson>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Lesson>
     */
    public function scopeByTeacher($query, int $enseignantId)
    {
        return $query->where('enseignant_id', $enseignantId);
    }

    /**
     * Scope: Ordonné
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Lesson>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Lesson>
     */
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
