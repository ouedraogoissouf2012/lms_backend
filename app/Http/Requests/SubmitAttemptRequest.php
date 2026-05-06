<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SubmitAttemptRequest - Submit quiz answers
 *
 * Purpose: Validate & authorize quiz submission with answer array structure
 * Authorization: Authenticated user (attempt ownership verified server-side)
 * Validation: answers array required; each answer structure validated against question types via server-side business logic
 * 10-year perspective: Answer submission creates immutable record for forensic analysis (answer patterns, time-to-answer correlation with performance)
 */
class SubmitAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'answers' => 'present|array',
            'answers.*' => 'nullable',
        ];
    }

    public function messages(): array
    {
        return [
            'answers.present' => 'Le champ réponses est requis.',
            'answers.array' => 'Les réponses doivent être un tableau.',
        ];
    }
}
