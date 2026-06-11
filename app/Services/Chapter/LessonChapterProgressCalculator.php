<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\Chapter;
use App\Models\ChapterProgress;

/**
 * Calcul du pourcentage de progression d'une leçon (par chapitres) pour un
 * utilisateur — ex-`ChapterProgress::getLessonProgress` (H2 audit, §5).
 *
 * 2 requêtes fixes (chapitres + progressions keyBy) — pas de N+1.
 */
final class LessonChapterProgressCalculator
{
    /**
     * @return array{
     *     total_chapters:int,
     *     completed_chapters:int,
     *     percentage:float|int,
     *     chapters:array<int,array<string,mixed>>
     * }
     */
    public function calculate(int $userId, int $lessonId): array
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

        $progressData = ChapterProgress::where('user_id', $userId)
            ->whereIn('chapter_id', $chapters->pluck('id'))
            ->get()
            ->keyBy('chapter_id');

        $completedChapters = 0;
        $chaptersProgress = [];

        foreach ($chapters as $chapter) {
            $progress = $progressData->get($chapter->id);
            $isCompleted = $progress !== null && $progress->completed_at !== null;

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
}
