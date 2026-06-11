<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\KnowledgeCheck;

/**
 * Règles d'accès par utilisateur à un knowledge check — ex-méthodes du modèle
 * `KnowledgeCheck` (H2 audit, §5 : jamais de requêtes DB dans un modèle).
 * Pattern identique à QuizAccessService.
 */
final class KnowledgeCheckAccessService
{
    /**
     * L'utilisateur a-t-il déjà réussi ce quiz ?
     */
    public function isPassedByUser(KnowledgeCheck $quiz, int $userId): bool
    {
        return $quiz->attempts()
            ->where('user_id', $userId)
            ->where('passed', true)
            ->exists();
    }

    /**
     * Meilleur score de l'utilisateur (null si aucune tentative).
     */
    public function bestScore(KnowledgeCheck $quiz, int $userId): ?int
    {
        return $quiz->attempts()
            ->where('user_id', $userId)
            ->max('score');
    }

    /**
     * L'utilisateur peut-il encore tenter le quiz (quota max_attempts) ?
     */
    public function canAttempt(KnowledgeCheck $quiz, int $userId): bool
    {
        if ($quiz->max_attempts === null) {
            return true;
        }

        $attemptCount = $quiz->attempts()
            ->where('user_id', $userId)
            ->count();

        return $attemptCount < $quiz->max_attempts;
    }
}
