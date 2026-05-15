<?php

namespace App\Http\Requests;

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
