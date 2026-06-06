<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;

/**
 * Recalcule les statistiques agrégées d'un Quiz (total_questions,
 * total_points, attempts_count, average_score) et persiste.
 *
 * Remplace le pattern `Quiz::updateStatistics()` + boot hooks
 * `QuizQuestion::saved/deleted` + `QuizAttempt::updated` qui déclenchait
 * 4 SELECT + 1 UPDATE à chaque sauvegarde d'une question (anti-pattern
 * N+1 d'écriture interdit par PRODUCTION_STANDARDS §5).
 *
 * Appel explicite par le caller au bon moment (après transaction, après
 * grading, etc.) — JAMAIS via un hook implicite.
 */
final class QuizStatisticsService
{
    /**
     * Recalcule et persiste les stats agrégées du Quiz.
     *
     * 4 requêtes d'agrégat + 1 UPDATE. À n'appeler qu'une fois par opération
     * métier (création complète, soumission tentative, suppression…), pas par
     * sauvegarde individuelle de question.
     */
    public function recompute(Quiz $quiz): void
    {
        $quiz->total_questions = $quiz->questions()->count();
        $quiz->total_points = $quiz->questions()->sum('points');
        $quiz->attempts_count = $quiz->attempts()->where('status', 'submitted')->count();

        $avgScore = $quiz->attempts()
            ->where('status', 'submitted')
            ->whereNotNull('score')
            ->avg('score');

        $quiz->average_score = $avgScore !== null ? round((float) $avgScore, 2) : null;

        $quiz->save();
    }
}
