<?php

declare(strict_types=1);

namespace App\Services\Lesson;

use App\Models\LessonProgress;

/**
 * State machine de progression sur une leçon — extraite de
 * `LessonProgress` (PERF-04 batch 3).
 *
 * ## Pourquoi extraire
 *
 * `LessonProgress::updateProgress` était une state machine (transitions
 * not_started → in_progress → completed selon le pourcentage), accumulateur
 * de temps, et clamp 0-100. Logique métier qui n'a pas sa place dans un
 * modèle Eloquent (§1.1).
 *
 * ## Comportement
 *
 * Aucun changement runtime — logique déplacée verbatim. `LessonProgress`
 * conserve un thin wrapper pour préserver les callers existants.
 *
 * ## DI strict (§1.6 D)
 *
 * Pas de dépendance constructeur — pure logique sur le model passé en
 * argument. Pas de Facade.
 *
 * @see app/Models/LessonProgress.php::updateProgress (thin wrapper)
 */
class LessonProgressService
{
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
