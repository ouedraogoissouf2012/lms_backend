<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * DeleteQuizRequest - Delete a quiz
 *
 * Purpose: Authorize quiz deletion (soft delete with audit trail)
 * Authorization: Quiz creator or admin only (owner check + role fallback)
 * 10-year perspective: Soft delete preserves historical quiz data for attempt audits and compliance reporting
 */
class DeleteQuizRequest extends FormRequest
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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
