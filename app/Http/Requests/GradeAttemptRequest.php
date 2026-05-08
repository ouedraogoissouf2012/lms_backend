<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * GradeAttemptRequest - Manually grade a quiz attempt
 *
 * Purpose: Validate & authorize manual grading with points & feedback
 * Authorization: Quiz creator or admin only (prevents student self-grading)
 * Validation: points_earned required (numeric, non-negative, <= points_possible), feedback optional (string)
 * 10-year perspective: Manual grades support essay/short-answer questions, enable teacher override for edge cases, create audit trail of instructor decisions
 */
class GradeAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        $attempt = $this->attempt();

        if (!$attempt) {
            abort(404, 'Tentative non trouvée');
        }

        return $user->isAdmin() || $attempt->quiz->created_by === $user->id;
    }

    public function rules(): array
    {
        $attempt = $this->attempt();

        return [
            'points_earned' => [
                'required',
                'numeric',
                'min:0',
                $attempt ? 'max:' . ($attempt->points_possible ?? 100) : 'max:1000',
            ],
            'feedback' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'points_earned.required' => 'Les points obtenus sont requis.',
            'points_earned.numeric' => 'Les points obtenus doivent être un nombre.',
            'points_earned.min' => 'Les points obtenus ne peuvent pas être négatifs.',
            'points_earned.max' => 'Les points obtenus ne peuvent pas dépasser le maximum possible.',
            'feedback.string' => 'Le feedback doit être une chaîne de caractères.',
            'feedback.max' => 'Le feedback ne peut pas dépasser 1000 caractères.',
        ];
    }

    private function attempt()
    {
        return \App\Models\QuizAttempt::find($this->route('id'));
    }
}
