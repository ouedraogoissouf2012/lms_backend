<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CheckTimeRemainingRequest - Check remaining time for attempt
 *
 * Purpose: Authorize time-check endpoint (prevents timing manipulation)
 * Authorization: Authenticated user (attempt ownership verified server-side)
 * Validation: No input fields; server enforces time expiration rules + auto-submission
 * 10-year perspective: Time checks provide cheating detection signals (frequent time-check patterns), enable proactive session recovery
 */
class CheckTimeRemainingRequest extends FormRequest
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
