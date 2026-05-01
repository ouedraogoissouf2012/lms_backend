<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * GenerateGradesReportRequest - Generate grades PDF report
 *
 * Purpose: Validate & authorize grades report generation with evaluation filtering
 * Authorization: Coordinateurs and superAdmins only (enforced by middleware + FormRequest)
 * 10-year perspective: Evaluation_id scoping prevents data leakage, date range enables historical analysis
 */
class GenerateGradesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return in_array($user->role, ['coordinateur', 'superAdmin', 'admin']);
    }

    public function rules(): array
    {
        return [
            'evaluation_id' => 'nullable|integer|exists:evaluations,id',
            'date_start' => 'nullable|date|date_format:Y-m-d',
            'date_end' => 'nullable|date|date_format:Y-m-d|after_or_equal:date_start',
        ];
    }

    public function messages(): array
    {
        return [
            'evaluation_id.exists' => 'L\'évaluation sélectionnée n\'existe pas.',
            'date_start.date' => 'La date de début doit être une date valide.',
            'date_start.date_format' => 'La date de début doit être au format YYYY-MM-DD.',
            'date_end.date' => 'La date de fin doit être une date valide.',
            'date_end.date_format' => 'La date de fin doit être au format YYYY-MM-DD.',
            'date_end.after_or_equal' => 'La date de fin doit être égale ou supérieure à la date de début.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Set defaults if date range not provided
        if (!$this->has('date_start') && !$this->has('date_end')) {
            $this->merge([
                'date_start' => now()->subMonth()->format('Y-m-d'),
                'date_end' => now()->format('Y-m-d'),
            ]);
        }
    }
}
