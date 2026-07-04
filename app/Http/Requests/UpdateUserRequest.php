<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ## Sécurité — rôle assignable borné (audit 2026-07-02, OWASP A01)
 * La règle {@see AssignableRole} empêche un acteur de promouvoir un utilisateur
 * (y compris lui-même) vers un rôle égal ou supérieur au sien. Sans elle, un
 * coordinateur pouvait s'auto-promouvoir `superAdmin` via `PUT /api/users/{id}`.
 */
final class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return $user !== null && ($user->isCoordinator() || $user->isAdmin());
    }

    public function rules(): array
    {
        $userId = $this->route('user');

        /** @var User|null $actor */
        $actor = $this->user();

        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $userId,
            'role' => ['sometimes', 'string', new AssignableRole($actor)],
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
