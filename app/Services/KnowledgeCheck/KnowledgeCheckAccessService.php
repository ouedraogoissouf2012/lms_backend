<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\KnowledgeCheck;

/**
 * Règles d'accès par utilisateur à un knowledge check — ex-méthodes du modèle
 * `KnowledgeCheck` (H2 audit, §5 : jamais de requêtes DB dans un modèle).
 * Pattern identique à QuizAccessService.
 *
 * TOUTES les lectures de tentatives passent par {@see self::attemptKeyspace()},
 * donc hors global scope `institution`. La cohérence n'est pas optionnelle : si
 * le quota comptait les tentatives héritées (`institution_id = NULL`) alors que
 * l'historique et le meilleur score les ignoraient, l'étudiant serait bloqué en
 * « quota atteint » devant une liste de tentatives VIDE — sans aucun moyen de
 * comprendre. Le quota et ce que l'étudiant lit doivent voir le même jeu de
 * lignes.
 */
final class KnowledgeCheckAccessService
{
    /**
     * L'utilisateur a-t-il déjà réussi ce quiz ?
     */
    public function isPassedByUser(KnowledgeCheck $quiz, int $userId): bool
    {
        return $this->attemptKeyspace($quiz, $userId)
            ->where('passed', true)
            ->exists();
    }

    /**
     * Meilleur score de l'utilisateur (null si aucune tentative).
     */
    public function bestScore(KnowledgeCheck $quiz, int $userId): ?int
    {
        $best = $this->attemptKeyspace($quiz, $userId)->max('score');

        return is_numeric($best) ? (int) $best : null;
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
