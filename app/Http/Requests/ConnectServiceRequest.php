<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ConnectServiceRequest — Connect external service integration
 *
 * Purpose: Validate external service connection (OAuth, API tokens, webhooks)
 * Authorization Model: Only superAdmin can configure integrations
 * Consideration: Validates service type and required credentials
 */
final class ConnectServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->asRoleEnum() === Role::Supradmin;
    }

    public function rules(): array
    {
        return [
            'service' => 'required|string|in:slack,teams,zapier,googleapis,github,custom',
            'api_key' => 'required|string|min:10',
            'api_secret' => 'sometimes|string|min:10',
            'webhook_url' => 'sometimes|url',
        ];
    }

    public function messages(): array
    {
        return [
            'service.required' => 'Le service est obligatoire.',
            'service.in' => 'Le service sélectionné n\'est pas supporté.',
            'api_key.required' => 'La clé API est obligatoire.',
            'webhook_url.url' => 'L\'URL webhook doit être valide.',
        ];
    }
}
