<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates AdminAnalyticsController - tasks dashboard/analytics request.
 *
 * ## Purpose
 * List pending administrative tasks.
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
final class GetPendingTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        return $user->isCoordinator() || $user->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
