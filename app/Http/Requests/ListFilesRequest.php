<?php

namespace App\Http\Requests;

use App\Models\User;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates query parameters of GET /api/files.
 *
 * ## Purpose (CRITICAL — issue #10)
 *
 * Apply a strict whitelist on `fileable_type` so a malicious user cannot
 * filter the listing by an arbitrary Eloquent class name
 * (e.g. `App\Models\User`) to enumerate or discover files attached to
 * resources they should not see.
 *
 * The same whitelist as the upload path is used — both come from
 * `config/fileables.php`, single source of truth.
 *
 * ## Authorization
 *
 * Auth is enforced upstream by `auth:sanctum`. Per-row authorization (private
 * vs public, owner vs admin) stays in the controller — this request only
 * sanitizes input.
 */
final class ListFilesRequest extends FormRequest
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
            'type' => [
                'sometimes',
                'string',
                'max:50',
            ],
            'category' => [
                'sometimes',
                'string',
                'in:course_material,assignment,resource,other,forum_attachment,profile_picture,general',
            ],
            'user_id' => [
                'sometimes',
                'integer',
                'min:1',
                $this->sesPropresFichiersSeulement(),
            ],
            'fileable_type' => [
                'sometimes',
                'string',
                'in:' . implode(',', array_keys(config('fileables.morph_map', []))),
            ],
            'fileable_id' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'sort' => [
                'sometimes',
                'string',
                'in:recent,name,size,downloads',
            ],
            'per_page' => [
                'sometimes',
                'integer',
                'min:1',
                'max:100',
            ],
        ];
    }

    /**
     * Un élève ne liste que ses propres fichiers (GHSA-gg7j) : cibler un autre
     * compte, c'est demander ce qu'un camarade a déposé. Fail-closed : tout
     * rôle qui n'est pas du personnel est traité comme un élève. Une valeur
     * non numérique est laissée à la règle `integer`, qui la refuse déjà.
     */
    private function sesPropresFichiersSeulement(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = $this->user();
            if (! $user instanceof User || $user->isStaff() || ! is_numeric($value)) {
                return;
            }
            if ((int) $value !== $user->id) {
                $fail('Vous ne pouvez lister que vos propres fichiers.');
            }
        };
    }

    public function messages(): array
    {
        return [
            'fileable_type.in' => 'Le type de ressource demandé n\'est pas autorisé.',
            'category.in'      => 'La catégorie demandée n\'est pas autorisée.',
            'sort.in'          => 'Tri demandé inconnu.',
            'per_page.max'     => 'per_page ne peut pas dépasser 100.',
        ];
    }
}
