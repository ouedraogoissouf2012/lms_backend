<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Rules\AssignableRole;
use App\Rules\UniqueEmailInInstitution;
use Illuminate\Foundation\Http\FormRequest;

/**
 * CreateUserRequest — Admin user creation with institution validation
 *
 * Purpose: Validate new user creation by administrators
 * Authorization Model: Only coordinateur/superAdmin roles can create users
 * Consideration: Validates user must belong to the same institution as admin
 *
 * ## Sécurité — rôle assignable borné (audit 2026-07-02, OWASP A01)
 * `authorize()` décide QUI peut créer un utilisateur ; la règle {@see AssignableRole}
 * décide QUEL rôle il peut assigner (jamais égal ou supérieur au sien). Sans elle,
 * un coordinateur pouvait créer un `superAdmin` — escalade verticale.
 *
 * ## Unicité de l'email bornée au tenant (#580, P1 de #563)
 * `unique:users,email` interrogeait la table SANS scope tenant : la contrainte était
 * globale à la plateforme alors que l'index est `(email, institution_id)`. La règle
 * {@see UniqueEmailInInstitution} la borne à l'institution de l'acteur — celle-là même
 * que `BelongsToInstitution::creating` écrira dans la ligne créée.
 */
final class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        return $user !== null && ($user->isCoordinator() || $user->isAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User|null $actor */
        $actor = $this->user();

        return [
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', UniqueEmailInInstitution::forCreationBy($actor)],
            'password' => 'required|string|min:8|confirmed',
            'role' => ['required', 'string', new AssignableRole($actor)],
            'klassci_id' => 'nullable|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Le nom est obligatoire.',
            'email.required' => 'L\'email est obligatoire.',
            // Pas de clé `email.unique` : le message vient désormais de la règle
            // UniqueEmailInInstitution, qui distingue compte actif et compte supprimé.
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
            'role.in' => 'Le rôle sélectionné est invalide.',
        ];
    }
}
