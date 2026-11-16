<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\Lesson;
use App\Models\LessonProgress;
use Illuminate\Support\Carbon;

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
     * La transition de DÉBUT, écrite une fois : une ligne `not_started` passe
     * `in_progress`, `started_at` posé. Rien pour une ligne déjà commencée ou
     * terminée : jamais de rétrogradation. Lue par la mise à jour du pourcentage
     * (ci-dessous) et par l'écriture du temps (`ChapterTimeRecorder`, #1147) :
     * du temps passé commence la leçon, comme un pourcentage non nul.
     *
     * @return array{status?: string, started_at?: Carbon}
     */
    public static function debut(LessonProgress $progress): array
    {
        return $progress->status === 'not_started' ? ['status' => 'in_progress', 'started_at' => now()] : [];
    }

    /**
     * Met à jour la progression d'un user sur une leçon.
     *
     * Transitions auto :
     *   - not_started + percentage > 0 → in_progress (`started_at` = now)
     *   - percentage >= 100 + not completed → completed (`completed_at` = now)
     *
     * Clamp `percentage` à [0, 100].
     *
     * Le temps passé n'est PAS écrit ici (#944) : `time_spent_minutes` est un
     * agrégat recalculé par ChapterTimeRecorder depuis le temps des chapitres.
     * Le cumuler depuis le client en ferait un second compteur, faussable.
     *
     * @return bool  Résultat du `update()` Eloquent.
     */
    public function updateProgress(LessonProgress $progress, int $percentage): bool
    {
        $data = [
            'progress_percentage' => min(100, max(0, $percentage)),
            'last_accessed_at' => now(),
        ];

        if ($percentage > 0) {
            $data += self::debut($progress);
        }

        // Auto-complétion si 100%
        if ($percentage >= 100 && $progress->status !== 'completed') {
            $data['status'] = 'completed';
            $data['completed_at'] = now();
        }

        return $progress->update($data);
    }
}
