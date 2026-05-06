<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates AdminAnalyticsController - trends dashboard/analytics request.
 *
 * ## Purpose
 * Analyze activity trends over time.
 *
 * ## Authorization Model
 * Authenticated user. No ownership check (read-only stateless).
 * Role checks in controller via middleware.
 *
 * ## Validation
 * Query parameters: date ranges, filters, aggregation options.
 * Minimal validation for stateless analytics endpoints.
 *
 * ## 10-year Consideration
 * Stateless endpoints: no data persistence required.
 * Cache headers managed per endpoint. Extend rules() for new filters.
 */
final class GetActivityTrendsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            'date_start' => 'sometimes|date_format:Y-m-d',
            'date_end' => 'sometimes|date_format:Y-m-d|after_or_equal:date_start',
            'limit' => 'sometimes|integer|min:1|max:1000',
        ];
    }
}