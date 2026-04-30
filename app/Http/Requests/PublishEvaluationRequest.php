<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates evaluation publish request (POST /api/evaluations/{id}/publish).
 *
 * ## Purpose
 * Authorize publication of evaluation (makes it visible to students).
 * No input fields required — only authorization checks.
 *
 * ## Authorization Model
 * 1. User authenticated
 * 2. User is NOT coordinateur
 * 3. Evaluation exists and belongs to user's institution
 * 4. Sets is_published = true in controller
 */
final class PublishEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        // Coordinators cannot publish evaluations
        if ($user->role === 'coordinateur') {
            return false;
        }

        // Evaluation must exist and belong to user's institution
        $evaluation = \App\Models\Evaluation::where('id', $this->route('id'))
            ->where('institution_id', $user->institution_id)
            ->first();

        if (!$evaluation) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
