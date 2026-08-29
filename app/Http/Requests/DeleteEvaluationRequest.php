<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation delete request (DELETE /api/evaluations/{id}).
 *
 * ## Purpose
 * Authorize deletion of evaluation.
 * The `canBeEdited()` business check (no students have submitted yet) is
 * performed by the controller, not here.
 *
 * ## Authorization
 * Delegated to {@see \App\Http\Requests\Concerns\ChecksEvaluationOwnership::checkEvaluationOwnership()}.
 * Identical behavior across DeleteEvaluationRequest / PublishEvaluationRequest /
 * UpdateEvaluationRequest (issue #125 refactor).
 */
final class DeleteEvaluationRequest extends FormRequest
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
