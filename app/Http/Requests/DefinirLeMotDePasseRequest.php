<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Role;
use App\Models\User;
use App\Services\User\GestionDeCompte;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/users/{user}/password` (#1275) : l'administration de l'école définit le mot de
 * passe d'un compte de son école.
 *
 * Définir le mot de passe d'un compte, c'est pouvoir se connecter à sa place. Seuls l'admin et
 * le superAdmin d'un établissement le peuvent (décision de la superviseure, réversible) : le
 * coordinateur, qui gère pourtant les comptes, contournerait la séparation des comptes. Le
 * supradmin de plateforme, sans établissement, est refusé comme à la création (#1181). La
 * cible reste un compte de l'école, de rang strictement inférieur, jamais soi-même
 * ({@see GestionDeCompte}, #932).
 */
final class DefinirLeMotDePasseRequest extends FormRequest
{
    private const ADMINISTRATION = [Role::Admin, Role::SuperAdmin];

    public function authorize(): bool
    {
        $acteur = $this->user();
        $cible = $this->route('user');

        return $acteur instanceof User && $cible instanceof User
            && in_array($acteur->asRoleEnum(), self::ADMINISTRATION, true)
            && is_int($acteur->institution_id) && $acteur->institution_id === $cible->institution_id
            && GestionDeCompte::admet($acteur, $cible);
    }

    /**
     * La règle de la création d'un compte (`CreateUserRequest`).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => 'required|string|min:8|confirmed',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Le mot de passe est obligatoire.',
            'password.min' => 'Le mot de passe doit contenir au moins 8 caractères.',
            'password.confirmed' => 'Les mots de passe ne correspondent pas.',
        ];
    }
}
