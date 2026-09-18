<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\Evaluation;
use App\Models\User;

/**
 * Authorization check shared by `DeleteEvaluationRequest`, `PublishEvaluationRequest`,
 * and `UpdateEvaluationRequest`. Factorise 37 lignes dupliquées × 3 en un seul site.
 *
 * Issue #125 (refactor) — promesse honorée des audits `spec-architect` de
 * PR #122 (#119) et PR #128 (#124).
 *
 * ## Invariants sécurité hérités
 *
 * Ce trait ne change PAS le comportement runtime des 3 FormRequests — il extrait
 * leur logique commune verbatim. Les invariants sécurité posés par les PRs
 * antérieures restent valides :
 *
 *  - **Coordinateurs exclus** : les coordinateurs n'ont pas vocation à modifier
 *    des évaluations (décision business pré-existante).
 *  - **Multi-tenant** : l'évaluation doit appartenir à l'institution de l'utilisateur
 *    authentifié (filtre `where('institution_id', $user->institution_id)`).
 *  - **Ownership write-once (issue #119)** : la lecture passe par
 *    `$user->klassci_enseignant_id` (colonne dédiée write-once, jamais réécrite
 *    par re-sync KLASSCI), JAMAIS par le blob `klassci_data['enseignant_id']`
 *    qui serait vulnérable à un re-sync compromis.
 *  - **Admin bypass** : `$user->isAdmin()` court-circuite le check d'ownership
 *    pour les rôles administratifs (admin, administrateur, superAdmin, supradmin).
 *
 * ## Usage
 *
 * ```php
 * final class DeleteEvaluationRequest extends FormRequest
 * {
 *     use \App\Http\Requests\Concerns\ChecksEvaluationOwnership;
 *
 *     public function authorize(): bool
 *     {
 *         return $this->checkEvaluationOwnership();
 *     }
 * }
 * ```
 *
 * ## Not for StoreEvaluationRequest
 *
 * Ce trait charge une évaluation EXISTANTE via `$this->route('id')`. Il est
 * INADAPTÉ au POST de création (`StoreEvaluationRequest`) — au moment du POST
 * il n'y a pas d'éval et pas de route id. `StoreEvaluationRequest` a sa propre
 * logique d'autorisation (admin/teacher only ; `klassci_enseignant_id` assigné
 * par le controller post-#124).
 *
 * @see \App\Http\Requests\DeleteEvaluationRequest
 * @see \App\Http\Requests\PublishEvaluationRequest
 * @see \App\Http\Requests\UpdateEvaluationRequest
 * @see \App\Http\Requests\StoreEvaluationRequest (intentionally NOT using this trait)
 * @see \App\Http\Requests\Concerns\ChecksEvaluationOwnership::checkEvaluationOwnership
 */
trait ChecksEvaluationOwnership
{
    /**
     * Returns true iff the authenticated user can act on the evaluation
     * referenced by `$this->route('id')`. False otherwise → 403.
     *
     * Ne dépend que de :
     *  - `auth()->user()` (pattern FormRequest standard)
     *  - `$this->route('id')` (resolved by FormRequest at runtime)
     */
    protected function checkEvaluationOwnership(): bool
    {
        $user = $this->authenticatedUserOrNull();

        if (!$user) {
            return false;
        }

        // Coordinators are excluded from evaluation mutations by business rule.
        if ($user->isCoordinator()) {
            return false;
        }

        return $this->mayActOnRoutedEvaluation($user);
    }

    /**
     * Returns true iff the authenticated user may READ the notes attached to the
     * routed evaluation. False otherwise -> 403.
     *
     * ## Pourquoi une seconde methode, et pas la premiere reutilisee
     *
     * `checkEvaluationOwnership()` refuse les coordinateurs : c'est une regle de
     * MUTATION (« un coordinateur ne modifie pas une evaluation »). La transposer
     * telle quelle a la lecture leur retirerait un acces que la route leur accorde
     * (`role:enseignant,coordinateur,superAdmin`) et pour lequel
     * {@see \App\Services\Evaluation\Teacher\TeacherEvaluationViewService::preview()}
     * ecrit deja une politique de supervision. Ce serait une regression deguisee
     * en durcissement.
     *
     * Ce qui est commun aux deux — quelle colonne porte la propriete, a quelle
     * valeur la comparer, et l'appartenance a l'institution — reste ecrit UNE
     * fois, dans {@see mayActOnRoutedEvaluation()}. C'est la raison d'etre de ce
     * trait : que la reponse a « qui possede cette evaluation » n'ait qu'un lieu.
     *
     * Le coordinateur reste borne a SON institution : c'est le `where` de
     * {@see routedEvaluationForInstitution()}, pas un blanc-seing.
     */
    protected function checkEvaluationReadAccess(): bool
    {
        $user = $this->authenticatedUserOrNull();

        if (!$user) {
            return false;
        }

        if ($user->isCoordinator()) {
            return $this->routedEvaluationForInstitution($user) !== null;
        }

        return $this->mayActOnRoutedEvaluation($user);
    }

    /**
     * Propriete, invariants #119 inclus — le seul endroit ou ils sont ecrits.
     *
     * Lecture depuis la colonne dediee write-once `users.klassci_enseignant_id`,
     * JAMAIS depuis le blob `klassci_data` qu'un re-sync compromis pourrait
     * reecrire. Une identite enseignant absente ne vaut pas propriete : elle
     * ferme, faute de quoi elle correspondrait aux evaluations orphelines —
     * meme fermeture par defaut que
     * {@see \App\Services\Search\TeacherOwnershipScope::applyToEvaluations()}.
     *
     * Contournement admin : admin / administrateur / superAdmin / supradmin.
     */
    private function mayActOnRoutedEvaluation(User $user): bool
    {
        $evaluation = $this->routedEvaluationForInstitution($user);

        if (!$evaluation) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        $userKlassciEnseignantId = $user->klassci_enseignant_id;

        return $userKlassciEnseignantId !== null
            && $evaluation->klassci_enseignant_id === $userKlassciEnseignantId;
    }

    /** L'evaluation routee, si elle existe DANS l'institution de l'appelant. */
    private function routedEvaluationForInstitution(User $user): ?Evaluation
    {
        return Evaluation::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();
    }

    private function authenticatedUserOrNull(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user;
    }
}
