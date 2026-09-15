<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Activation d'un compte par jeton à usage unique (#803, ADR-803-02).
 *
 * Route ANONYME par nature : celui qui active n'a précisément pas encore de
 * quoi s'authentifier. Le jeton EST l'autorisation, et il est vérifié par le
 * service — pas ici : une règle de validation qui interrogerait la table
 * distinguerait « jeton inconnu » de « jeton déjà servi », ce qui dirait à un
 * inconnu qu'un lien a existé.
 *
 * `confirmed` exige `password_confirmation` : sur un écran sans possibilité de
 * renvoyer le lien, une faute de frappe enfermerait le titulaire dehors.
 */
final class ActivateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function jeton(): string
    {
        $jeton = $this->input('token');

        return is_string($jeton) ? $jeton : '';
    }

    public function motDePasse(): string
    {
        $mdp = $this->input('password');

        return is_string($mdp) ? $mdp : '';
    }
}
