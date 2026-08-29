<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ViewAuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Strict lowercase 'supradmin' UNIQUEMENT : le journal d'audit est
        // cross-tenant (toutes institutions). NE PAS utiliser asRoleEnum() qui
        // normalise 'superAdmin' (admin intra-tenant) en Role::Supradmin et
        // laisserait un admin d'institution lire les logs des autres tenants.
        // Même distinction que ChecksForumAuthorization / RateLimitServiceProvider.
        return $this->user()?->isPlatformSupradmin() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'action' => 'sometimes|string|in:create,update,delete,view,export,import,login,logout,login_failed',
            'user_id' => 'sometimes|integer|exists:users,id',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.in' => 'L\'action doit être une valeur valide.',
            'user_id.exists' => 'L\'utilisateur n\'existe pas.',
        ];
    }
}
