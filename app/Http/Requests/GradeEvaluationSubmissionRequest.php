<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #588 — notation manuelle d'une soumission d'évaluation.
 */
final class GradeEvaluationSubmissionRequest extends FormRequest
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
        return [
            'points' => ['required', 'array', 'min:1'],
            'points.*' => ['numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
