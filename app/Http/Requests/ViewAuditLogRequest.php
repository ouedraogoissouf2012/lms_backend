<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ViewAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && in_array(auth()->user()->role, ['superAdmin']);
    }

    public function rules(): array
    {
        return [
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'action' => 'sometimes|string|in:create,update,delete,view,export,import',
            'user_id' => 'sometimes|integer|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'action.in' => 'L\'action doit être une valeur valide.',
            'user_id.exists' => 'L\'utilisateur n\'existe pas.',
        ];
    }
}
