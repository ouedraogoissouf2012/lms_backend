<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * GenerateAttendanceReportRequest - Generate attendance PDF report
 *
 * Purpose: Validate & authorize attendance report generation with date filtering
 * Authorization: Coordinateurs and superAdmins only (enforced by middleware + FormRequest)
 * 10-year perspective: Date range validation prevents abuse, classe_id enables institutional scoping
 */
class GenerateAttendanceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user !== null && ($user->isCoordinator() || $user->isAdmin());
    }

    public function rules(): array
    {
        return [
            'date_start' => 'nullable|date|date_format:Y-m-d',
            'date_end' => 'nullable|date|date_format:Y-m-d|after_or_equal:date_start',
            'classe_id' => 'nullable|integer|exists:classes,id',
        ];
    }

    public function messages(): array
    {
        return [
            'date_start.date' => 'La date de début doit être une date valide.',
            'date_start.date_format' => 'La date de début doit être au format YYYY-MM-DD.',
            'date_end.date' => 'La date de fin doit être une date valide.',
            'date_end.date_format' => 'La date de fin doit être au format YYYY-MM-DD.',
            'date_end.after_or_equal' => 'La date de fin doit être égale ou supérieure à la date de début.',
            'classe_id.exists' => 'La classe sélectionnée n\'existe pas.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Set defaults if not provided
        if (!$this->has('date_start') && !$this->has('date_end')) {
            $this->merge([
                'date_start' => now()->subMonth()->format('Y-m-d'),
                'date_end' => now()->format('Y-m-d'),
            ]);
        }
    }
}
