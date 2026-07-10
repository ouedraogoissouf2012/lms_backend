<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * StartAttemptRequest - Begin a new quiz attempt
 *
 * Purpose: Authorize quiz attempt initialization
 * Authorization: Authenticated students only (availability + attempt limits verified server-side)
 * Validation: No input fields required; business rules (quiz available, user can attempt) enforced in controller
 * 10-year perspective: Attempt tracking enables learner progress monitoring, supports spaced repetition analytics, audit compliance
 */
class StartAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }
}
