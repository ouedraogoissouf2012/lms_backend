<?php

declare(strict_types=1);

namespace App\Services\Quiz;

use App\Models\Quiz;
use App\Models\QuizAttempt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

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
     * Nombre de tentatives ayant CONSOMMÉ un essai : tous les statuts sauf
     * `abandoned`.
     *
     * Ne comptait auparavant que `submitted` / `graded` (#581) : une tentative
     * `in_progress` ne consommait donc rien, et trois onglets ouverts donnaient
     * trois tentatives notables sur un quiz à `max_attempts = 1` — c'est la
     * meilleure des trois notes qui était retenue. Une tentative ouverte
     * consomme un essai, comme dans un examen réel ; seul un abandon explicite
     * (ou le janitor) la libère.
     */
    public function attemptsCountForUser(Quiz $quiz, int $userId): int
    {
        return $this->attemptKeyspace($quiz, $userId)
            ->where('status', '!=', 'abandoned')
            ->count();
    }

    /**
     * Tentative encore ouverte de l'utilisateur, s'il en a une.
     *
     * C'est elle que `startAttempt` rend à l'étudiant plutôt que d'en créer une
     * seconde : fermer son onglet ne doit ni bloquer, ni redonner du temps.
     */
    public function activeAttemptForUser(Quiz $quiz, int $userId): ?QuizAttempt
    {
        return $this->attemptKeyspace($quiz, $userId)
            ->where('status', 'in_progress')
            ->first();
    }

    /**
     * Tentatives d'un couple (quiz, étudiant) telles que **la base** les voit —
     * global scope `institution` retiré.
     *
     * L'unique `quiz_attempts_quiz_id_user_id_attempt_number_unique` ne connaît
     * pas `institution_id`. Or la colonne a été ajoutée nullable et **sans
     * backfill** (`2026_02_11_000002`), puis laissée nullable à dessein (#583) :
     * toute tentative antérieure est invisible au scope dès qu'un tenant est
     * résolu, alors que l'index continue de la faire respecter. `max + 1`
     * re-proposait alors un numéro déjà pris → **409 définitif** (même défaut
     * qu'en #540).
     *
     * Sans risque cross-tenant : le filtre est ancré sur `quiz_id`, déjà
     * rattaché à une seule institution.
     *
     * @return Builder<QuizAttempt>
     */
    private function attemptKeyspace(Quiz $quiz, int $userId): Builder
    {
        return QuizAttempt::withoutGlobalScope('institution')
            ->where('quiz_id', $quiz->id)
            ->where('user_id', $userId);
    }

    /**
     * L'utilisateur peut-il encore tenter le quiz (dispo + quota) ?
     *
     * Une tentative REPRENABLE vaut « oui » même quand le quota nominal est
     * atteint : sans ce terme, un étudiant ayant une tentative en cours sur un
     * quiz à `max_attempts = 1` verrait `user_can_attempt = false` dans la
     * liste — l'interface lui annoncerait « plus de tentative » alors qu'il peut
     * légitimement continuer la sienne (#581).
     */
    public function canUserAttempt(Quiz $quiz, int $userId): bool
    {
        if (!$this->isAvailable($quiz)) {
            return false;
        }

        return $this->quotaAllows(
            $quiz,
            hasResumable: $this->activeAttemptForUser($quiz, $userId) !== null,
            consumed: $this->attemptsCountForUser($quiz, $userId),
        );
    }

    /**
     * Même règle que {@see self::canUserAttempt()}, mais à partir des
     * tentatives DÉJÀ chargées — pour les listes paginées, qui doivent rester
     * à zéro requête par ligne (#546).
     *
     * Elle existe pour que la règle de quota ne soit énoncée qu'à UN endroit :
     * `QuizCrudService::list()` en portait auparavant sa propre copie, restée
     * sur l'ancienne sémantique (seules les tentatives finalisées comptaient).
     *
     * @param  EloquentCollection<int, QuizAttempt>  $attempts  tentatives
     *         consommantes de l'étudiant sur ce quiz (cf. {@see self::consumingAttemptsByQuiz()})
     */
    public function canAttemptFromLoaded(Quiz $quiz, EloquentCollection $attempts): bool
    {
        if (!$this->isAvailable($quiz)) {
            return false;
        }

        return $this->quotaAllows(
            $quiz,
            hasResumable: $attempts->contains(
                static fn (QuizAttempt $attempt): bool => $attempt->status === 'in_progress'
            ),
            consumed: $attempts->count(),
        );
    }

    /**
     * LA règle de quota, énoncée une seule fois : une tentative reprenable
     * l'emporte sur le compteur, sinon le quota nominal s'applique.
     */
    private function quotaAllows(Quiz $quiz, bool $hasResumable, int $consumed): bool
    {
        return $hasResumable || $consumed < $quiz->max_attempts;
    }

    /**
     * Prochain numéro de tentative — `max + 1` sur TOUTES les tentatives
     * (`in_progress` / `abandoned` incluses), jamais `count + 1` : un trou dans
     * la série ferait re-proposer un numéro déjà pris, en collision frontale
     * avec l'unique (fix E2E #211, généralisé en #540).
     */
    public function nextAttemptNumberForUser(Quiz $quiz, int $userId): int
    {
        $highest = $this->attemptKeyspace($quiz, $userId)->max('attempt_number');

        return is_numeric($highest) ? ((int) $highest) + 1 : 1;
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
     * Tentatives CONSOMMANTES (tous statuts sauf `abandoned`) de `$userId` pour
     * un ensemble de quiz, groupées par `quiz_id`, triées `score DESC` — une
     * requête au lieu de N (#546, `QuizCrudService::list` sur une page paginée).
     *
     * Portait auparavant sur les seules tentatives finalisées : la liste
     * ignorait donc les tentatives en cours, sous-comptait le quota et
     * dupliquait la règle (#581). Le tri `score DESC` global AVANT le `groupBy`
     * préserve, au sein de chaque groupe, l'ordre qu'aurait produit la requête
     * par-quiz d'origine — les tentatives en cours (score `NULL`) se rangent
     * comme le ferait le même SQL, sans tri PHP divergent.
     *
     * Global scope `institution` retiré : voir {@see self::attemptKeyspace()}.
     *
     * @param  array<int, int>  $quizIds
     * @return Collection<array-key, EloquentCollection<int, QuizAttempt>>
     */
    public function consumingAttemptsByQuiz(array $quizIds, int $userId): Collection
    {
        if ($quizIds === []) {
            return collect();
        }

        return QuizAttempt::withoutGlobalScope('institution')
            ->whereIn('quiz_id', $quizIds)
            ->where('user_id', $userId)
            ->where('status', '!=', 'abandoned')
            ->orderBy('score', 'desc')
            ->get()
            ->groupBy('quiz_id');
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
