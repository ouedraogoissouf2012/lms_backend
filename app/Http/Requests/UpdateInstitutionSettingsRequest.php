<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateInstitutionSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['superAdmin']);
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'logo_url' => 'sometimes|url',
            'primary_color' => 'sometimes|string|regex:/^#(?:[0-9a-f]{3}){1,2}$/i',
            'klassci_api_url' => 'sometimes|url',
            'klassci_api_token' => 'sometimes|string',
        ];
    }

    public function messages(): array
    {
        return [
            'primary_color.regex' => 'La couleur doit être un code hexadécimal valide.',
            'klassci_api_url.url' => 'L\'URL doit être valide.',
        ];
    }
}
