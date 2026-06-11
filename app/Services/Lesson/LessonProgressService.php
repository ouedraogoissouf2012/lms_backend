<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Lesson;
use App\Models\LessonProgress;

/**
 * Progression sur une leçon : state machine de mise à jour (PERF-04 batch 3)
 * + lectures/statistiques par leçon (H2 audit — extraites du modèle `Lesson`,
 * §5 : jamais de requêtes DB dans un modèle).
 */
class LessonProgressService
{
    /**
     * Progression d'un utilisateur sur une leçon (null si jamais commencée).
     */
    public function progressForUser(Lesson $lesson, int $userId): ?LessonProgress
    {
        return $lesson->progress()->where('user_id', $userId)->first();
    }

    /**
     * Taux de complétion moyen de la leçon (0-100).
     */
    public function averageCompletionRate(Lesson $lesson): float
    {
        $completedCount = $lesson->progress()->where('status', 'completed')->count();
        $totalCount = $lesson->progress()->count();

        return $totalCount > 0 ? ($completedCount / $totalCount) * 100 : 0;
    }

    /**
     * Nombre d'étudiants ayant commencé (in_progress ou completed).
     */
    public function studentsStartedCount(Lesson $lesson): int
    {
        return $lesson->progress()
            ->whereIn('status', ['in_progress', 'completed'])
            ->count();
    }

    /**
     * Nombre d'étudiants ayant terminé.
     */
    public function studentsCompletedCount(Lesson $lesson): int
    {
        return $lesson->progress()->where('status', 'completed')->count();
    }

    /**
     * Marque la leçon comme complétée (100%, horodatée).
     */
    public function complete(LessonProgress $progress): bool
    {
        return $progress->update([
            'status' => 'completed',
            'progress_percentage' => 100,
            'completed_at' => now(),
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Enregistre une note (clamp 1-5) + feedback optionnel.
     */
    public function addRating(LessonProgress $progress, int $rating, ?string $feedback = null): bool
    {
        return $progress->update([
            'rating' => min(5, max(1, $rating)),
            'feedback' => $feedback,
        ]);
    }

    /**
     * Met à jour la progression d'un user sur une leçon.
     *
     * Transitions auto :
     *   - not_started + percentage > 0 → in_progress (`started_at` = now)
     *   - percentage >= 100 + not completed → completed (`completed_at` = now)
     *
     * Accumule `time_spent_minutes` si fourni. Clamp `percentage` à [0, 100].
     *
     * @return bool  Résultat du `update()` Eloquent.
     */
    public function updateProgress(
        LessonProgress $progress,
        int $percentage,
        ?int $timeSpentMinutes = null
    ): bool {
        $data = [
            'progress_percentage' => min(100, max(0, $percentage)),
            'last_accessed_at' => now(),
        ];

        if ($progress->status === 'not_started' && $percentage > 0) {
            $data['status'] = 'in_progress';
            $data['started_at'] = now();
        }

        if ($timeSpentMinutes !== null) {
            $data['time_spent_minutes'] = $progress->time_spent_minutes + $timeSpentMinutes;
        }

        // Auto-complétion si 100%
        if ($percentage >= 100 && $progress->status !== 'completed') {
            $data['status'] = 'completed';
            $data['completed_at'] = now();
        }

        return $progress->update($data);
    }
}
