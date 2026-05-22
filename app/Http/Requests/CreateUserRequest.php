<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CreateUserRequest — Admin user creation with institution validation
 *
 * Purpose: Validate new user creation by administrators
 * Authorization Model: Only coordinateur/superAdmin roles can create users
 * Consideration: Validates user must belong to the same institution as admin
 */
final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return $user !== null && ($user->isCoordinator() || $user->isAdmin());
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|string|in:student,enseignant,coordinateur,superAdmin',
            'klassci_id' => 'nullable|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L\'email est obligatoire.',
            'email.unique' => 'Cet email est déjà utilisé.',
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
            'role.in' => 'Le rôle sélectionné est invalide.',
        ];
    }
}
