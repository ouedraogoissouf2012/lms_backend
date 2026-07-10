<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * SaveProgressRequest - Save quiz attempt progress without submission
 *
 * Purpose: Validate & authorize progress saving (temporary answer storage)
 * Authorization: Authenticated user (attempt ownership verified server-side)
 * Validation: answers array required; allows partial answers (nil entries) for in-progress state
 * 10-year perspective: Progress snapshots enable recovery-from-disconnect feature, provide heatmaps of question difficulty via time-to-completion
 */
class SaveProgressRequest extends FormRequest
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
        return [
            'answers' => 'present|array',
            'answers.*' => 'nullable',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'answers.present' => 'Le champ réponses est requis.',
            'answers.array' => 'Les réponses doivent être un tableau.',
        ];
    }
}
