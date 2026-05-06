<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class BulkImportUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['coordinateur', 'superAdmin']);
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:csv,xlsx,xls|max:5242880',
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Un fichier est obligatoire.',
            'file.mimes' => 'Le fichier doit être au format CSV ou Excel.',
        ];
    }
}
