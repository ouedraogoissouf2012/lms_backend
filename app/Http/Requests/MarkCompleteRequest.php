<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates LessonController mutation request.
 *
 * ## Purpose
 * Mark lesson as complete.
 *
 * ## Authorization Model
 * Authenticated user with appropriate role/ownership.
 * Authorization checked in controller via performMarkComplete().
 *
 * ## 10-year Consideration
 * Input validation rules stable. Extend rules() for new fields.
 */
final class MarkCompleteRequest extends FormRequest
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
