<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;

final class AuthorizeServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->user()?->asRoleEnum() === Role::Supradmin;
    }

    public function rules(): array
    {
        return [
            'service' => 'required|string|in:slack,teams,zapier,googleapis,github,custom',
            'auth_code' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'service.in' => 'Le service n\'est pas supporté.',
            'auth_code.required' => 'Le code d\'autorisation est obligatoire.',
        ];
    }
}
