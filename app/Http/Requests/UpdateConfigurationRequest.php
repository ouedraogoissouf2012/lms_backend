<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Routes declarees `role:superAdmin` : l'admin d'etablissement est la cible.
        // Le gestionnaire de plateforme y accede aussi. Avant #102, un seul test
        // suffisait car tryFromString('superAdmin') renvoyait Role::Supradmin.
        $role = auth()->user()?->asRoleEnum();

        return $role === Role::SuperAdmin || $role === Role::Supradmin;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'key' => 'required|string|max:255',
            'value' => 'required',
            'category' => 'required|string|in:general,security,email,integrations,learning',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.required' => 'La clé de configuration est obligatoire.',
            'value.required' => 'La valeur est obligatoire.',
            'category.in' => 'La catégorie doit être valide.',
        ];
    }
}
