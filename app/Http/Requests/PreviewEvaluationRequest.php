<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Authorizes the teacher-side preview of an evaluation
 * (GET /api/evaluations/{id}/preview).
 *
 * ## Authorization
 * A READ : `ChecksEvaluationOwnership::checkEvaluationReadAccess()`,
 * the rule `results-by-class` and `submissions` already follow. The owner and
 * the admins read ; the coordinator keeps supervision within their institution,
 * further limited to finished evaluations by
 * `TeacherEvaluationViewService::preview()`.
 *
 * Before this request, the role alone was checked : any teacher of the
 * institution could read the questions of a colleague's evaluation.
 */
final class PreviewEvaluationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationReadAccess();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
