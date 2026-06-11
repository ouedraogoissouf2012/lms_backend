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
     * Nombre de tentatives FINALISÉES par un utilisateur.
     *
     * Fix E2E #211 : le grading auto passe la tentative en `graded` (pas
     * `submitted`) — filtrer `submitted` seul faisait sauter le quota
     * `max_attempts` ET provoquait un 500 (attempt_number dupliqué) au
     * restart d'un quiz auto-gradé.
     */
    public function attemptsCountForUser(Quiz $quiz, int $userId): int
    {
        return $quiz->attempts()
            ->where('user_id', $userId)
            ->whereIn('status', ['submitted', 'graded'])
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
     * Prochain numéro de tentative pour un utilisateur — max+1 sur TOUTES
     * les tentatives (in_progress/abandoned incluses). Fix E2E #211 :
     * `attemptsCountForUser + 1` entrait en collision avec la contrainte
     * unique (quiz_id, user_id, attempt_number) dès qu'une tentative
     * non finalisée existait.
     */
    public function nextAttemptNumberForUser(Quiz $quiz, int $userId): int
    {
        return (int) $quiz->attempts()
            ->where('user_id', $userId)
            ->max('attempt_number') + 1;
    }

    /**
     * Meilleure tentative finalisée (score décroissant). Inclut `graded`
     * (grading auto) — même fix E2E #211 que attemptsCountForUser.
     */
    public function bestAttemptForUser(Quiz $quiz, int $userId): ?QuizAttempt
    {
        return $quiz->attempts()
            ->where('user_id', $userId)
            ->whereIn('status', ['submitted', 'graded'])
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
