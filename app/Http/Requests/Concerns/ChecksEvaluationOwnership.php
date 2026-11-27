<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Http\Requests\DeleteEvaluationRequest;
use App\Http\Requests\PublishEvaluationRequest;
use App\Http\Requests\StoreEvaluationRequest;
use App\Http\Requests\UpdateEvaluationRequest;
use App\Models\Evaluation;
use App\Models\User;
use App\Services\Evaluation\Teacher\TeacherEvaluationViewService;
use App\Services\Search\TeacherOwnershipScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 *  - **Hors de portée : introuvable (#1782)** : une évaluation absente, supprimée ou
 *    d'une AUTRE école rend 404, du même corps dans les trois cas, AVANT tout contrôle
 *    de droit ({@see evaluationDeLEcoleOuIntrouvable()}). Un 403 dirait qu'elle existe
 *    ailleurs ; le 403 reste pour un droit manquant dans SON école.
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
 * logique d'autorisation (admin/teacher only) ; l'auteur est établi par la
 * source de programmation de l'école (`EvaluationProgramming`, D72).
 *
 * @see DeleteEvaluationRequest
 * @see PublishEvaluationRequest
 * @see UpdateEvaluationRequest
 * @see StoreEvaluationRequest (intentionally NOT using this trait)
 * @see ChecksEvaluationOwnership::checkEvaluationOwnership
 */
trait ChecksEvaluationOwnership
{
    /**
     * Returns true iff the authenticated user can act on the evaluation
     * referenced by `$this->route('id')`. False otherwise → 403 ; hors de
     * portée → 404 (#1782).
     *
     * Ne dépend que de :
     *  - `auth()->user()` (pattern FormRequest standard)
     *  - `$this->route('id')` (resolved by FormRequest at runtime)
     */
    protected function checkEvaluationOwnership(): bool
    {
        $user = $this->authenticatedUserOrNull();

        if (! $user) {
            return false;
        }
        $this->evaluationDeLEcoleOuIntrouvable($user);

        // Coordinators are excluded from evaluation mutations by business rule.
        if ($user->isCoordinator()) {
            return false;
        }

        return $this->mayActOnRoutedEvaluation($user);
    }

    /**
     * Returns true iff the authenticated user may READ the notes attached to the
     * routed evaluation. False otherwise -> 403 ; hors de portee -> 404 (#1782).
     *
     * ## Pourquoi une seconde methode, et pas la premiere reutilisee
     *
     * `checkEvaluationOwnership()` refuse les coordinateurs : c'est une regle de
     * MUTATION (« un coordinateur ne modifie pas une evaluation »). La transposer
     * telle quelle a la lecture leur retirerait un acces que la route leur accorde
     * (`role:enseignant,coordinateur,superAdmin`) et pour lequel
     * {@see TeacherEvaluationViewService::preview()}
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

        if (! $user) {
            return false;
        }
        $this->evaluationDeLEcoleOuIntrouvable($user);

        // Le coordinateur lit les évaluations de SON école. La portée est relue ici, et non
        // déduite du contrôle ci-dessus : la règle tient d'elle-même si ce contrôle venait à
        // manquer (défense en profondeur).
        if ($user->isCoordinator()) {
            return $this->routedEvaluationForInstitution($user) !== null;
        }

        return $this->mayActOnRoutedEvaluation($user);
    }

    /**
     * Propriete, invariants #119 inclus — le seul endroit ou ils sont ecrits.
     *
     * Deux preuves, sans comparaison de mode (D72) : l'auteur local
     * (`evaluations.enseignant_id`, ecrit par les deux sources a la creation),
     * ou l'identite KLASSCI pour les lignes d'avant D72.
     *
     * Lecture depuis la colonne dediee write-once `users.klassci_enseignant_id`,
     * JAMAIS depuis le blob `klassci_data` qu'un re-sync compromis pourrait
     * reecrire. Une identite enseignant absente ne vaut pas propriete : elle
     * ferme, faute de quoi elle correspondrait aux evaluations orphelines —
     * meme fermeture par defaut que
     * {@see TeacherOwnershipScope::applyToEvaluations()}.
     *
     * Contournement admin : admin / administrateur / superAdmin / supradmin.
     */
    private function mayActOnRoutedEvaluation(User $user): bool
    {
        $evaluation = $this->routedEvaluationForInstitution($user);

        if (! $evaluation) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        // L'auteur local (`enseignant_id`, écrit à chaque création depuis D72),
        // ou, pour une ligne d'avant D72, l'identité KLASSCI. Une identité
        // NULL ne vaut jamais propriété, d'un côté comme de l'autre.
        if (is_int($user->getKey()) && $evaluation->enseignant_id === $user->getKey()) {
            return true;
        }

        $userKlassciEnseignantId = $user->klassci_enseignant_id;

        return $userKlassciEnseignantId !== null
            && $evaluation->klassci_enseignant_id === $userKlassciEnseignantId;
    }

    /**
     * #1782 : l'evaluation routee existe-t-elle, non supprimee, DANS l'ecole de
     * l'appelant ? Sinon elle est introuvable : 404, avec un message FIXE, donc le
     * meme corps pour une evaluation absente, supprimee ou d'une autre ecole. Un
     * appelant sans ecole n'a aucune evaluation dans sa portee.
     *
     * @throws NotFoundHttpException
     */
    private function evaluationDeLEcoleOuIntrouvable(User $user): void
    {
        if ($this->routedEvaluationForInstitution($user) === null) {
            throw new NotFoundHttpException('Évaluation introuvable.');
        }
    }

    /**
     * L'evaluation routee, si elle existe DANS l'institution de l'appelant. Sans
     * institution, aucune : `where('institution_id', null)` s'ecrirait
     * `IS NULL` et designerait une evaluation sans institution. La regle tient
     * d'elle-meme, quelle que soit la garde placee devant elle.
     */
    private function routedEvaluationForInstitution(User $user): ?Evaluation
    {
        if (! is_int($user->institution_id)) {
            return null;
        }

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
