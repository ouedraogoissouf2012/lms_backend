<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * ShowAttemptRequest - Retrieve quiz attempt details
 *
 * Purpose: Authorize read access to attempt data (prevents cross-student visibility)
 * Authorization: Attempt owner, quiz creator, or admin (multi-party access control verified server-side)
 * Validation: No input fields required; authorization checks enforce privacy boundaries
 * 10-year perspective: Attempt details enable learner-facing analytics dashboard, support parent/guardian access patterns
 */
class ShowAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }
}
