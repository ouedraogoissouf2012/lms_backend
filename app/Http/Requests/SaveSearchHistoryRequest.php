<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates SearchController mutation request.
 *
 * ## Purpose
 * Record search in history.
 *
 * ## Authorization Model
 * Authenticated user with appropriate role/ownership.
 * Authorization checked in controller via performSaveSearchHistory().
 *
 * ## 10-year Consideration
 * Input validation rules stable. Extend rules() for new fields.
 */
final class SaveSearchHistoryRequest extends FormRequest
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
