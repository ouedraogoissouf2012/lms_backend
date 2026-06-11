<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\QuizAttempt;

/**
 * État temporel et transitions simples d'une tentative de quiz — extrait du
 * modèle `QuizAttempt` (H2 audit : §5 « Modèles max 150 lignes »).
 *
 * Regroupe : expiration (durée du quiz dépassée), temps restant, formatage
 * du temps passé, transitions start/abandon.
 */
final class QuizAttemptTimerService
{
    /**
     * La tentative a-t-elle dépassé la durée autorisée du quiz ?
     */
    public function hasExpired(QuizAttempt $attempt): bool
    {
        if (!$attempt->quiz->duration_minutes || !$attempt->started_at) {
            return false;
        }

        $deadline = $attempt->started_at->addMinutes($attempt->quiz->duration_minutes);

        return now()->isAfter($deadline);
    }

    /**
     * Temps restant en secondes (null si pas de durée / pas démarré / terminé).
     */
    public function timeRemaining(QuizAttempt $attempt): ?int
    {
        if (!$attempt->quiz->duration_minutes || !$attempt->started_at || $attempt->status !== 'in_progress') {
            return null;
        }

        $deadline = $attempt->started_at->addMinutes($attempt->quiz->duration_minutes);
        $remaining = now()->diffInSeconds($deadline, false);

        return max(0, (int) $remaining);
    }

    /**
     * Temps passé formaté pour affichage ("1h 02m 05s", "5m 30s", "45s", "N/A").
     */
    public function formattedTimeSpent(QuizAttempt $attempt): string
    {
        if (!$attempt->time_spent_seconds) {
            return 'N/A';
        }

        $hours = floor($attempt->time_spent_seconds / 3600);
        $minutes = floor(($attempt->time_spent_seconds % 3600) / 60);
        $seconds = $attempt->time_spent_seconds % 60;

        if ($hours > 0) {
            return sprintf('%dh %02dm %02ds', $hours, $minutes, $seconds);
        }

        if ($minutes > 0) {
            return sprintf('%dm %02ds', $minutes, $seconds);
        }

        return sprintf('%ds', $seconds);
    }

    /**
     * Démarre la tentative.
     */
    public function start(QuizAttempt $attempt): bool
    {
        return $attempt->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    /**
     * Abandonne la tentative.
     */
    public function abandon(QuizAttempt $attempt): bool
    {
        return $attempt->update(['status' => 'abandoned']);
    }
}
