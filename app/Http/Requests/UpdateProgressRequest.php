<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates LessonController mutation request.
 *
 * ## Purpose
 * Update lesson progress tracking.
 *
 * ## Authorization Model
 * Authenticated user with appropriate role/ownership.
 * Authorization checked in controller via performUpdateProgress().
 *
 * ## 10-year Consideration
 * Input validation rules stable. Extend rules() for new fields.
 */
final class UpdateProgressRequest extends FormRequest
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
        return [];
    }
}
