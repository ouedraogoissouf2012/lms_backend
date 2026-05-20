<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation publish request (POST /api/evaluations/{id}/publish).
 *
 * ## Purpose
 * Authorize publication of evaluation (makes it visible to students).
 * No input fields required — only authorization checks.
 *
 * ## Authorization
 * Delegated to {@see \App\Http\Requests\Concerns\ChecksEvaluationOwnership::checkEvaluationOwnership()}.
 * Identical behavior across DeleteEvaluationRequest / PublishEvaluationRequest /
 * UpdateEvaluationRequest (issue #125 refactor).
 */
final class PublishEvaluationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationOwnership();
    }

    public function rules(): array
    {
        return [];
    }
}
