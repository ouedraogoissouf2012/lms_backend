<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Traits\BelongsToInstitution;

/**
 * Model Chapter
 *
 * Représente un chapitre/section d'une leçon
 * NOUVELLE STRUCTURE: Chapter appartient à Lesson (inversé)
 */
class Chapter extends Model
{
    /** @use HasFactory<\Database\Factories\ChapterFactory> */
    use HasFactory, SoftDeletes, BelongsToInstitution;

    protected $fillable = [
        'lesson_id',
        'matiere_id',
        'enseignant_id',
        'title',
        'description',
        'content_type',
        'content',
        'video_url',
        'video_provider',
        'pdf_url',
        'audio_url',
        'presentation_url',
        'external_link',
        'file_original_path',
        'file_converted_path',
        'slides_images',
        'slides_count',
        'notes_enseignant',
        'allow_download',
        'show_slide_numbers',
        'autoplay_video',
        'order',
        'duration_minutes',
        'institution_id',
    ];

    protected $casts = [
        'slides_images' => 'array',
        'notes_enseignant' => 'array',
        'allow_download' => 'boolean',
        'show_slide_numbers' => 'boolean',
        'autoplay_video' => 'boolean',
    ];

    protected $attributes = [
        'content_type' => 'text',
        'allow_download' => true,
        'show_slide_numbers' => true,
        'autoplay_video' => false,
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
     * Relation: Enseignant créateur
     *
     * @return BelongsTo<User, $this>
     */
    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /**
     * Relation: Leçon parente (NOUVELLE STRUCTURE)
     * Un chapitre appartient à une leçon
     *
     * @return BelongsTo<Lesson, $this>
     */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /**
     * Scope: Ordonné
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Chapter>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Chapter>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    /**
     * Scope: Par matière
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Chapter>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Chapter>
     */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /**
     * Scope: Par enseignant
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Chapter>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Chapter>
     */
    public function scopeByTeacher($query, int $enseignantId)
    {
        return $query->where('enseignant_id', $enseignantId);
    }

    /**
     * Scope: Par leçon
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Chapter>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Chapter>
     */
    public function scopeForLesson($query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }

    /**
     * Scope: Par type de contenu
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Chapter>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Chapter>
     */
    public function scopeByContentType($query, string $contentType)
    {
        return $query->where('content_type', $contentType);
    }
}
