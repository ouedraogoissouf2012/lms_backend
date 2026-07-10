<?php

namespace App\Http\Requests;

use App\Models\Quiz;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * PublishQuizRequest - Publish a quiz
 *
 * Purpose: Authorize quiz publication (requires creator/admin + questions validation)
 * Authorization: Quiz creator or admin only (owner check + role fallback)
 * Validation: No input fields required, but business logic verified server-side (questions exist, valid state)
 * 10-year perspective: Publication creates immutable snapshot for audit trail, enables historical analysis of quiz effectiveness
 */
class PublishQuizRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        $quiz = $this->route('quiz');

        if ($user === null || ! $quiz instanceof Quiz) {
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }
}
