<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\BelongsToInstitution;

/**
 * Progression d'un utilisateur sur un chapitre
 */
class ChapterProgress extends Model
{
    use HasFactory, BelongsToInstitution;

    protected $table = 'chapter_progress';

    protected $fillable = [
        'user_id',
        'chapter_id',
        'completed_at',
        'time_spent_seconds',
        'quiz_score',
        'quiz_passed',
        'institution_id',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
        'quiz_passed' => 'boolean',
        'time_spent_seconds' => 'integer',
        'quiz_score' => 'integer',
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec le chapitre
     */
    public function chapter()
    {
        return $this->belongsTo(Chapter::class);
    }

    /**
     * Verifier si le chapitre est complete
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Marquer le chapitre comme complete
     */
    public function markAsCompleted(): self
    {
        $this->completed_at = now();
        $this->save();
        return $this;
    }

    /**
     * Obtenir ou creer une progression pour un utilisateur et un chapitre
     */
    public static function getOrCreate(int $userId, int $chapterId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId, 'chapter_id' => $chapterId],
            ['time_spent_seconds' => 0]
        );
    }

    /**
     * Calculer le pourcentage de progression d'une lecon pour un utilisateur
     */
    public static function getLessonProgress(int $userId, int $lessonId): array
    {
        $chapters = Chapter::where('lesson_id', $lessonId)->orderBy('position')->get();
        $totalChapters = $chapters->count();

        if ($totalChapters === 0) {
            return [
                'total_chapters' => 0,
                'completed_chapters' => 0,
                'percentage' => 0,
                'chapters' => [],
            ];
        }

        $progressData = static::where('user_id', $userId)
            ->whereIn('chapter_id', $chapters->pluck('id'))
            ->get()
            ->keyBy('chapter_id');

        $completedChapters = 0;
        $chaptersProgress = [];

        foreach ($chapters as $chapter) {
            $progress = $progressData->get($chapter->id);
            $isCompleted = $progress && $progress->isCompleted();

            if ($isCompleted) {
                $completedChapters++;
            }

            $chaptersProgress[] = [
                'chapter_id' => $chapter->id,
                'title' => $chapter->title,
                'position' => $chapter->position,
                'content_type' => $chapter->content_type,
                'is_completed' => $isCompleted,
                'completed_at' => $progress?->completed_at?->toIso8601String(),
                'quiz_score' => $progress?->quiz_score,
                'quiz_passed' => $progress?->quiz_passed ?? false,
                'time_spent_seconds' => $progress?->time_spent_seconds ?? 0,
            ];
        }

        return [
            'total_chapters' => $totalChapters,
            'completed_chapters' => $completedChapters,
            'percentage' => round(($completedChapters / $totalChapters) * 100),
            'chapters' => $chaptersProgress,
        ];
    }

    /**
     * Verifier si l'utilisateur peut acceder au chapitre suivant
     * (en fonction des quiz obligatoires)
     */
    public static function canAccessChapter(int $userId, Chapter $chapter): bool
    {
        // Si position est null ou 0, c'est le premier chapitre - toujours accessible
        if ($chapter->position === null || $chapter->position === 0) {
            return true;
        }

        // Trouver le chapitre precedent
        $previousChapter = Chapter::where('lesson_id', $chapter->lesson_id)
            ->whereNotNull('position')
            ->where('position', '<', $chapter->position)
            ->orderBy('position', 'desc')
            ->first();

        if (!$previousChapter) {
            return true;
        }

        // Verifier si le chapitre precedent est un quiz obligatoire
        if ($previousChapter->content_type === 'quiz') {
            $quiz = KnowledgeCheck::where('chapter_id', $previousChapter->id)
                ->where('is_active', true)
                ->first();

            if ($quiz && $quiz->is_required) {
                // Verifier si l'utilisateur a reussi le quiz
                $progress = static::where('user_id', $userId)
                    ->where('chapter_id', $previousChapter->id)
                    ->first();

                return $progress && $progress->quiz_passed;
            }
        }

        // Verifier si le chapitre precedent est complete
        $previousProgress = static::where('user_id', $userId)
            ->where('chapter_id', $previousChapter->id)
            ->first();

        return $previousProgress && $previousProgress->isCompleted();
    }
}
