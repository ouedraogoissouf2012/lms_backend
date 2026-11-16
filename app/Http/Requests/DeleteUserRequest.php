<?php

namespace App\Http\Requests;

use App\Models\User;
use App\Services\User\GestionDeCompte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #932 : l'acteur ne supprime qu'un compte de rang strictement inférieur au sien,
 * jamais le sien ({@see GestionDeCompte}, la règle de la modification aussi). La cible
 * vient du binding `{user}`, déjà borné à l'établissement.
 */
final class DeleteUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        $cible = $this->route('user');

        return $user instanceof User && ($user->isCoordinator() || $user->isAdmin())
            && $cible instanceof User && GestionDeCompte::admet($user, $cible);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }
}
