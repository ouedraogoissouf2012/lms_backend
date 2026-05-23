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
        /** @var User|null $user */
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        // Coordinators are excluded from evaluation mutations by business rule.
        if ($user->isCoordinator()) {
            return false;
        }

        // Evaluation must exist and belong to user's institution.
        $evaluation = Evaluation::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (!$evaluation) {
            return false;
        }

        // Ownership check (issue #119) — read from the write-once dedicated
        // column `users.klassci_enseignant_id` (never from the volatile blob).
        // Admin bypass: full role bypass (admin / administrateur / superAdmin / supradmin).
        if (!$user->isAdmin()) {
            $userKlassciEnseignantId = $user->klassci_enseignant_id;
            if ($userKlassciEnseignantId === null
                || $evaluation->klassci_enseignant_id !== $userKlassciEnseignantId) {
                return false;
            }
        }

        return true;
    }
}
