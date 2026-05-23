<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates TeacherStatsController - stats dashboard/analytics request.
 *
 * ## Purpose
 * Get teacher statistics and performance.
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
final class GetTeacherStatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (!$user) {
            return false;
        }

        return $user->isTeacher() || $user->isCoordinator() || $user->isAdmin();
    }

    public function rules(): array
    {
        return [];
    }
}