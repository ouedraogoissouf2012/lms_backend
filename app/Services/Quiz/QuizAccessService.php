<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;

/**
 * Règles d'accès et cycle de vie d'un Quiz — extrait du modèle `Quiz`
 * (H2 audit : §5 « Modèles max 150 lignes, jamais de requêtes DB »).
 *
 * Regroupe : disponibilité (fenêtre temporelle + statut publication),
 * droits de tentative par utilisateur, transitions de statut.
 */
final class QuizAccessService
{
    /**
     * Le quiz est-il publié (statut + date de publication passée) ?
     */
    public function isPublished(Quiz $quiz): bool
    {
        return $quiz->status === 'published'
            && $quiz->published_at !== null
            && $quiz->published_at->isPast();
    }

    /**
     * Le quiz est-il disponible maintenant (publié + fenêtre ouverte) ?
     */
    public function isAvailable(Quiz $quiz): bool
    {
        if (!$this->isPublished($quiz)) {
            return false;
        }

        if ($quiz->available_from && $quiz->available_from->isFuture()) {
            return false;
        }

        if ($quiz->available_until && $quiz->available_until->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Nombre de tentatives soumises par un utilisateur.
     */
    public function attemptsCountForUser(Quiz $quiz, int $userId): int
    {
        return $quiz->attempts()
            ->where('user_id', $userId)
            ->where('status', 'submitted')
            ->count();
    }

    /**
     * L'utilisateur peut-il encore tenter le quiz (dispo + quota) ?
     */
    public function canUserAttempt(Quiz $quiz, int $userId): bool
    {
        if (!$this->isAvailable($quiz)) {
            return false;
        }

        return $this->attemptsCountForUser($quiz, $userId) < $quiz->max_attempts;
    }

    /**
     * Meilleure tentative soumise (score décroissant).
     */
    public function bestAttemptForUser(Quiz $quiz, int $userId): ?QuizAttempt
    {
        return $quiz->attempts()
            ->where('user_id', $userId)
            ->where('status', 'submitted')
            ->orderBy('score', 'desc')
            ->first();
    }

    /**
     * Dernière tentative (tous statuts confondus).
     */
    public function latestAttemptForUser(Quiz $quiz, int $userId): ?QuizAttempt
    {
        return $quiz->attempts()
            ->where('user_id', $userId)
            ->orderBy('created_at', 'desc')
            ->first();
    }

    /**
     * Publie le quiz (statut + horodatage).
     */
    public function publish(Quiz $quiz): bool
    {
        return $quiz->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    /**
     * Archive le quiz.
     */
    public function archive(Quiz $quiz): bool
    {
        return $quiz->update(['status' => 'archived']);
    }
}
