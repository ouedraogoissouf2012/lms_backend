<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ChangeRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['superAdmin']);
    }

    public function rules(): array
    {
        return [
            'role' => 'required|string|in:student,enseignant,coordinateur,superAdmin',
        ];
    }

    public function messages(): array
    {
        return [
            'role.required' => 'Le rôle est obligatoire.',
            'role.in' => 'Le rôle sélectionné est invalide.',
        ];
    }
}
