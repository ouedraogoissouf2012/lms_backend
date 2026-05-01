<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * GetAttemptsRequest - Retrieve all attempts for a quiz
 *
 * Purpose: Authorize & validate query parameters for attempt listing (teacher analytics)
 * Authorization: Quiz creator or admin only (enforces creator-only visibility)
 * Validation: Optional user_id filter, per_page pagination (integer bounds)
 * 10-year perspective: Attempt aggregates enable cohort analytics, time-series performance tracking across academic years, identifies struggling students for intervention
 */
class GetAttemptsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $quiz = $this->route('quiz');

        if (!$quiz) {
            return false;
        }

        return $user->isAdmin() || $quiz->created_by === $user->id;
    }

    public function rules(): array
    {
        return [
            'user_id' => 'nullable|integer|exists:users,id',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.integer' => 'L\'ID utilisateur doit être un nombre entier.',
            'user_id.exists' => 'L\'utilisateur spécifié n\'existe pas.',
            'per_page.integer' => 'Le nombre d\'éléments par page doit être un nombre entier.',
            'per_page.min' => 'Le nombre d\'éléments par page doit être au minimum 1.',
            'per_page.max' => 'Le nombre d\'éléments par page ne peut pas dépasser 100.',
        ];
    }
}
