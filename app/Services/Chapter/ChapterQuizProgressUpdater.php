<?php

declare(strict_types=1);

namespace App\Services\Chapter;

use App\Models\ChapterProgress;

/**
 * Report d'une tentative de quiz sur la progression du chapitre.
 *
 * Extrait de `KnowledgeCheckAttemptService` (#540) : la progression d'un
 * chapitre est un concept du domaine « parcours », pas du domaine « tentative
 * de quiz ». Les garder dans la même classe donnait deux raisons de changer à
 * un seul service (§1.6 S) — et poussait le fichier au-delà de la limite de
 * 300 lignes (§1.1) dès l'ajout du quota.
 *
 * @see \App\Services\KnowledgeCheck\KnowledgeCheckAttemptService
 */
final class ChapterQuizProgressUpdater
{
    /**
     * Reporte une tentative sur la progression du chapitre :
     *   - conserve le MEILLEUR score (`quiz_score`), jamais le dernier ;
     *   - marque réussi + complété si la tentative est réussie ;
     *   - accumule le temps passé sur toutes les tentatives.
     *
     * Logique conservée à l'identique du service d'origine.
     */
    public function recordAttempt(
        int $userId,
        int $chapterId,
        int $score,
        bool $passed,
        int $timeSpentSeconds
    ): void {
        $progress = ChapterProgress::firstOrCreate(
            ['user_id' => $userId, 'chapter_id' => $chapterId],
            ['time_spent_seconds' => 0]
        );

        if ($progress->quiz_score === null || $score > $progress->quiz_score) {
            $progress->quiz_score = $score;
        }

        if ($passed) {
            $progress->quiz_passed = true;
            if (! $progress->completed_at) {
                $progress->completed_at = now();
            }
        }

        $progress->time_spent_seconds = ((int) $progress->time_spent_seconds) + $timeSpentSeconds;
        $progress->save();
    }
}
