<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->asRoleEnum() === Role::Supradmin;
    }

    public function rules(): array
    {
        return [
            'key' => 'required|string|max:255',
            'value' => 'required',
            'category' => 'required|string|in:general,security,email,integrations,learning',
        ];
    }

    public function messages(): array
    {
        return [
            'key.required' => 'La clé de configuration est obligatoire.',
            'value.required' => 'La valeur est obligatoire.',
            'category.in' => 'La catégorie doit être valide.',
        ];
    }
}
