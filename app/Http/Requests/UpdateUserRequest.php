<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['coordinateur', 'superAdmin']);
    }

    public function rules(): array
    {
        $userId = $this->route('user');
        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $userId,
            'role' => 'sometimes|string|in:student,enseignant,coordinateur,superAdmin',
            'klassci_id' => 'nullable|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Cet email est déjà utilisé.',
            'role.in' => 'Le rôle sélectionné est invalide.',
        ];
    }
}
