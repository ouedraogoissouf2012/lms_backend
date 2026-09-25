<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes pushing an evaluation's grades to KLASSCI
 * (POST /api/evaluations/{id}/sync-klassci and /sync-notes).
 *
 * ## Authorization
 * Synchronising declares the grades published (`notes_published`) and marks
 * the copies synced : it is a MUTATION, owned by the same rule as publishing —
 * `ChecksEvaluationOwnership::checkEvaluationOwnership()`.
 * Coordinators are therefore excluded, as they are from `publish`, although the
 * route's role list admits them.
 *
 * Before this request, the role alone was checked : any teacher of the
 * institution could push a colleague's grades with their own KLASSCI token.
 */
final class SyncEvaluationNotesRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationOwnership();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
