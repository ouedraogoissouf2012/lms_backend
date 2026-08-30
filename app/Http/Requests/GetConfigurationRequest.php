<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

/**
 * GetConfigurationRequest — Retrieve institution configuration (read-only)
 *
 * Purpose: Validate configuration retrieval with optional filtering
 * Authorization Model: Only superAdmin can view all configurations
 * Consideration: Returns institution-specific config via TenantManager
 */
final class GetConfigurationRequest extends FormRequest
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
            'category' => 'sometimes|string|in:general,security,email,integrations,learning',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.in' => 'La catégorie doit être valide.',
        ];
    }
}
