<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation start requests (POST /api/evaluations/{id}/start).
 *
 * ## Purpose
 * Authorize a student to start an evaluation. Time window validation and
 * question-presence checks are performed by `EvaluationController::startEvaluation`
 * (they depend on KLASSCI API + DB state, not on the request payload).
 *
 * ## Authorization model (issue #123)
 * - Authenticated student only — coordinateurs / enseignants ne démarrent pas
 *   d'évaluation pour eux-mêmes.
 * - Identité étudiant dérivée du token Sanctum (`$user->klassci_id`), jamais
 *   lue depuis le body. Aucun champ `klassci_etudiant_id` n'est validé ici —
 *   sa lecture depuis le client était un vecteur d'IDOR (issue #123).
 *
 * ## 10-year consideration
 * Time-window validation managed by KLASSCI (start/end times).
 * Practice mode enabled for terminated evaluations (revision).
 * If KLASSCI window logic changes, update controller + docs together.
 */
final class StartEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Issue #123: seuls les étudiants démarrent une évaluation. Les rôles
        // prof/coordinateur/admin n'ont pas vocation à passer une éval pour
        // eux-mêmes. Si un cas légitime émerge (admin qui démarre pour debug),
        // ouvrir une issue dédiée — cf. design.md §11 critère d'invalidation 1.
        return auth()->user()?->isStudent() === true;
    }

    public function rules(): array
    {
        return [];
    }
}
