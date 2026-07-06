<?php

namespace App\Models;

use App\Models\Traits\BelongsToInstitution;
use Database\Factories\ChapterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Model Chapter
 *
 * Représente un chapitre/section d'une leçon
 * NOUVELLE STRUCTURE: Chapter appartient à Lesson (inversé)
 */
class Chapter extends Model
{
    /** @use HasFactory<ChapterFactory> */
    use BelongsToInstitution, HasFactory, SoftDeletes;

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

    /** @return BelongsTo<Matiere, $this> Matière liée. */
    public function matiere(): BelongsTo
    {
        return $this->belongsTo(Matiere::class);
    }

    /** @return BelongsTo<User, $this> Enseignant créateur. */
    public function enseignant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enseignant_id');
    }

    /** @return BelongsTo<Lesson, $this> Leçon parente (un chapitre appartient à une leçon). */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return HasMany<SeanceRecording, $this> Enregistrements rattachés à ce chapitre. */
    public function seanceRecordings(): HasMany
    {
        return $this->hasMany(SeanceRecording::class);
    }

    /**
     * Scope: Ordonné
     *
     * @param  Builder<Chapter>  $query
     * @return Builder<Chapter>
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order')->orderBy('created_at');
    }

    /**
     * Scope: Par matière
     *
     * @param  Builder<Chapter>  $query
     * @return Builder<Chapter>
     */
    public function scopeForMatiere($query, int $matiereId)
    {
        return $query->where('matiere_id', $matiereId);
    }

    /**
     * Scope: Par enseignant
     *
     * @param  Builder<Chapter>  $query
     * @return Builder<Chapter>
     */
    public function scopeByTeacher($query, int $enseignantId)
    {
        return $query->where('enseignant_id', $enseignantId);
    }

    /**
     * Scope: Par leçon
     *
     * @param  Builder<Chapter>  $query
     * @return Builder<Chapter>
     */
    public function scopeForLesson($query, int $lessonId)
    {
        return $query->where('lesson_id', $lessonId);
    }

    /**
     * Scope: Par type de contenu
     *
     * @param  Builder<Chapter>  $query
     * @return Builder<Chapter>
     */
    public function scopeByContentType($query, string $contentType)
    {
        return $query->where('content_type', $contentType);
    }
}
