<?php

namespace App\Http\Requests;

use App\Rules\PositiveInteger;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/lessons query parameters.
 *
 * ## Purpose
 * Protects listing endpoint from:
 * - DOS via per_page=1000000
 * - Type injection via unvalidated type field
 * - Invalid IDs (negative, zero, non-numeric)
 *
 * ## Query Parameters
 * - per_page: int, 1-100 (pagination size)
 * - matiere_id: positive int (subject filter)
 * - classe_id: positive int (class filter)
 * - enseignant_id: positive int (teacher filter)
 * - type: enum (lesson type filter)
 * - status: enum (publication status filter)
 *
 * ## Security Model
 * No authorization() needed for public listing (no user context required).
 * If filtering should be restricted, add authorize() to check user role.
 *
 * ## 10-year perspective
 * Query parameter validation prevents:
 * 1. DOS attacks (per_page limits)
 * 2. SQL injection (ID validation before query scope)
 * 3. Type confusion (enum validation on status/type)
 *
 * If pagination limit changes (1-100 → 1-50), change only here.
 * All code using FilterLessonsRequest automatically uses new limit.
 */
final class FilterLessonsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * GET requests for listing typically don't require authorization
     * (data is public or filtered by role at model level).
     *
     * @return bool
     */
    public function authorize(): bool
    {
        // No explicit authorization needed for listing
        // Controller may filter results by user role via model scope
        return true;
    }

    /**
     * Get the validation rules for query parameters.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100', // DOS prevention: limit page size
            ],
            'matiere_id' => [
                'sometimes',
                new PositiveInteger(),
            ],
            'classe_id' => [
                'sometimes',
                new PositiveInteger(),
            ],
            'enseignant_id' => [
                'sometimes',
                new PositiveInteger(),
            ],
            'type' => [
                'sometimes',
                'string',
                'in:cours,tp,td,projet,autre',
            ],
            'status' => [
                'sometimes',
                'string',
                'in:draft,published,archived',
            ],
        ];
    }

    /**
     * Custom error messages for filter validation.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            'per_page.min' => 'Le nombre d\'éléments doit être au moins 1',
            'per_page.max' => 'Le nombre d\'éléments ne doit pas dépasser 100',
            'per_page.integer' => 'Le nombre d\'éléments doit être un nombre entier',
            'type.in' => 'Le type de cours doit être: cours, tp, td, projet ou autre',
            'status.in' => 'Le statut doit être: draft, published ou archived',
        ];
    }

    /**
     * Prepare data for validation.
     *
     * Normalize query parameters:
     * - Set default per_page if not provided
     * - Convert numeric strings to integers (query params are strings)
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        // Default per_page to 15 if not specified
        if (!$this->has('per_page')) {
            $this->merge(['per_page' => 15]);
        }

        // Convert string integers to actual integers for validation
        // Query parameters arrive as strings, but our rules expect integers
        $this->merge([
            'per_page' => (int) $this->per_page,
            'matiere_id' => $this->matiere_id ? (int) $this->matiere_id : null,
            'classe_id' => $this->classe_id ? (int) $this->classe_id : null,
            'enseignant_id' => $this->enseignant_id ? (int) $this->enseignant_id : null,
        ]);
    }
}
