<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates InstitutionController mutation request.
 *
 * ## Purpose
 * Delete institution (admin only).
 *
 * ## Authorization Model
 * Authenticated user with appropriate role/ownership.
 * Authorization checked in controller via performDeleteInstitution().
 *
 * ## 10-year Consideration
 * Input validation rules stable. Extend rules() for new fields.
 */
final class DeleteInstitutionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [];
    }
}