<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
 * @property int|null $matiere_id
 * @property int|null $classe_id
 * @property int|null $enseignant_id
 * @property string $status
 * @property int $order
 * @property \Illuminate\Support\Carbon|null $published_at
 * @property \Illuminate\Support\Carbon|null $created_at
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

    /** @return BelongsTo<Matiere, $this> Matière liée. */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /** @return BelongsTo<Classe, $this> Classe liée. */
    public function classe(): BelongsTo
    {
        return $this->belongsTo(Classe::class);
    }

    /** @return BelongsTo<User, $this> Enseignant créateur. */
    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /** @return HasMany<LessonProgress, $this> Progression des étudiants. */
    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    /** @return MorphMany<File, $this> Fichiers attachés au cours. */
    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    /** @return HasMany<Chapter, $this> Chapitres de la leçon (une leçon contient plusieurs chapitres). */
    public function chapters(): HasMany
    {
        return $this->hasMany(Chapter::class)->orderBy('order')->orderBy('created_at');
    }

    /** @return HasMany<LessonResource, $this> Ressources complémentaires. */
    public function resources(): HasMany
    {
        return $this->hasMany(LessonResource::class)->ordered();
    }

    /** @param Builder<Lesson> $query
     * @return Builder<Lesson> */
    public function scopePublished(Builder $query)
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
    /** @param Builder<Lesson> $query
     * @return Builder<Lesson> */
    public function scopeForMatiere(Builder $query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }
    /** @param Builder<Lesson> $query
     * @return Builder<Lesson> */
    public function scopeForClasse(Builder $query, int $classeId)
    {
        return $query->where('classe_id', $classeId);
    }
    /** @param Builder<Lesson> $query
     * @return Builder<Lesson> */
    public function scopeByTeacher(Builder $query, int $enseignantId)
    {
        return $query->where('enseignant_id', $enseignantId);
    }
    /** @param Builder<Lesson> $query
     * @return Builder<Lesson> */
    public function scopeOrdered(Builder $query)
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
