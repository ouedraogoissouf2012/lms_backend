<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ExportUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['coordinateur', 'superAdmin']);
    }

    public function rules(): array
    {
        return [
            'format' => 'required|string|in:csv,xlsx',
        ];
    }

    public function messages(): array
    {
        return [
            'format.in' => 'Le format doit être CSV ou XLSX.',
        ];
    }
}
