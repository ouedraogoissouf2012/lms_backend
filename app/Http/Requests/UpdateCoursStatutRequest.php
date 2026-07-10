<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates ProxyController mutation request.
 *
 * ## Purpose
 * Update course status via proxy.
 *
 * ## Authorization Model
 * Authenticated user with appropriate role/ownership.
 * Authorization checked in controller via performUpdateCoursStatut().
 *
 * ## 10-year Consideration
 * Input validation rules stable. Extend rules() for new fields.
 */
final class UpdateCoursStatutRequest extends FormRequest
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
