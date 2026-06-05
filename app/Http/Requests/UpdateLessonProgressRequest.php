<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valide la mise à jour de progression d'une leçon (POST /api/lessons/{id}/progress).
 */
final class UpdateLessonProgressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'progress_percentage' => 'required|integer|min:0|max:100',
            'time_spent_minutes' => 'nullable|integer|min:0',
        ];
    }
}
