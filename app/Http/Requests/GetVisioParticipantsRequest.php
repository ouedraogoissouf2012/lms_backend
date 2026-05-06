<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates LMSDataController - visio query request.
 *
 * ## Purpose
 * Fetch visio session participants.
 *
 * ## Authorization Model
 * Authenticated user. No ownership check (read-only).
 *
 * ## Validation
 * Query parameters: pagination (page, per_page), filters, sorting.
 * Minimal validation for read-only endpoints.
 *
 * ## 10-year Consideration
 * Filter fields stable. Extend rules() for new filter params.
 */
final class GetVisioParticipantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100',
        ];
    }
}