<?php

declare(strict_types=1);

namespace App\Services\KnowledgeCheck;

use App\Models\KnowledgeCheck;
use App\Models\KnowledgeCheckAttempt;
use Illuminate\Database\Eloquent\Builder;

/**
 * Règles d'accès par utilisateur à un knowledge check — ex-méthodes du modèle
 * `KnowledgeCheck` (H2 audit, §5 : jamais de requêtes DB dans un modèle).
 * Pattern identique à QuizAccessService.
 *
 * DETTE TRACÉE (#540) : `isPassedByUser()` et `bestScore()` interrogent encore
 * `$quiz->attempts()`, donc avec le global scope `institution`. Une tentative
 * héritée à `institution_id = NULL` y reste invisible — un étudiant ayant
 * réussi avant février 2026 peut donc être affiché comme non-réussi. Le défaut
 * est réel mais antérieur et purement d'affichage ; il ne provoque pas le 409
 * que corrige {@see self::attemptKeyspace()}. Non corrigé ici pour ne pas
 * modifier des données affichées hors du périmètre de #540.
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
     *
     * `max_attempts === null` signifie « illimité ».
     */
    public function canAttempt(KnowledgeCheck $quiz, int $userId): bool
    {
        if ($quiz->max_attempts === null) {
            return true;
        }

        return $this->attemptKeyspace($quiz, $userId)->count() < $quiz->max_attempts;
    }

    /**
     * Prochain numéro de tentative — `max + 1` sur toutes les tentatives de
     * l'utilisateur, jamais `count + 1` : une suppression administrative
     * laisserait un trou et `count + 1` re-proposerait un numéro déjà pris,
     * en collision avec l'unique `kc_attempts_user_attempt_unique` (#540).
     *
     * Miroir exact de {@see \App\Services\Quiz\QuizAccessService::nextAttemptNumberForUser}.
     */
    public function nextAttemptNumberForUser(KnowledgeCheck $quiz, int $userId): int
    {
        $highest = $this->attemptKeyspace($quiz, $userId)->max('attempt_number');

        return is_numeric($highest) ? ((int) $highest) + 1 : 1;
    }

    /**
     * Tentatives d'un couple (quiz, étudiant) telles que **la base** les voit —
     * global scope `institution` retiré.
     *
     * ## Pourquoi retirer le scope ici, et seulement ici
     *
     * L'index `kc_attempts_user_attempt_unique` porte sur
     * `(knowledge_check_id, user_id, attempt_number)` : il ne connaît PAS
     * `institution_id`. Or `2026_02_11_000002_add_institution_id_to_all_tables`
     * a ajouté cette colonne nullable **sans backfill**, et elle est restée
     * nullable à dessein (comptes plateforme, #583). Toute tentative antérieure
     * porte donc `institution_id = NULL` et devient invisible au scope dès qu'un
     * tenant est résolu — alors que l'index, lui, la voit toujours.
     *
     * Conséquence mesurée avant correctif : `max + 1` re-proposait un numéro
     * déjà pris, l'insertion violait l'unique, aucune tentative n'était
     * reprenable → **409 définitif** pour cet étudiant sur ce quiz.
     * Le comptage du quota souffrait du même angle mort.
     *
     * ## Pourquoi c'est sans risque cross-tenant
     *
     * Le filtre est ancré sur `knowledge_check_id`, une clé déjà rattachée à une
     * seule institution, et sur l'`user_id` appelant. Aucune ligne d'un autre
     * tenant ne peut entrer dans ce jeu de résultats.
     *
     * @return \Illuminate\Database\Eloquent\Builder<KnowledgeCheckAttempt>
     */
    private function attemptKeyspace(KnowledgeCheck $quiz, int $userId): Builder
    {
        return KnowledgeCheckAttempt::withoutGlobalScope('institution')
            ->where('knowledge_check_id', $quiz->id)
            ->where('user_id', $userId);
    }
}
