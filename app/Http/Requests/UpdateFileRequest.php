<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Validates file metadata update requests (PUT /api/files/{id}).
 *
 * ## Purpose
 * Update file metadata while enforcing ownership AND tenant isolation.
 * Updatable: category, description, visibility.
 * Prevents unauthorized file metadata hijacking and cross-tenant tampering.
 *
 * ## Updatable Fields
 * - category: document classification (course_material, assignment, resource, other)
 * - description: optional file description (max 500 chars)
 * - is_public: visibility toggle (boolean)
 *
 * ## Authorization Model (issue #102 fix)
 *
 * Strict tenant isolation via `ChecksFileAuthorization::canModerateFile()`:
 *   1. supradmin → allow (platform manager bypass)
 *   2. file.institution_id !== user.institution_id → deny
 *   3. file.user_id === user.id → allow (owner)
 *   4. user.role ∈ [admin, administrateur, superAdmin] → allow (admin intra-tenant)
 *   5. else → deny
 *
 * Previous version used `$user->isAdmin()` which ambiguously allowed any admin
 * (including intra-tenant ones) to update files cross-tenant (HIGH IDOR).
 *
 * ## 10-year consideration
 * Enum values (category) must match File model fillable + UploadFileRequest rules.
 * If categories change, update: rules(), messages(), and UploadFileRequest together.
 */
final class UpdateFileRequest extends FormRequest
{
    use ChecksFileAuthorization;

    /**
     * Verify file ownership + tenant isolation before allowing update.
     */
    public function authorize(): bool
    {
        $user = Auth::user();
        $file = $this->route('file');

        return $this->canModerateFile(
            $file instanceof File ? $file : null,
            $user instanceof User ? $user : null,
        );
    }

    /**
     * Get validation rules for file metadata update.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'category' => [
                'sometimes',
                'string',
                'in:course_material,assignment,resource,other,forum_attachment,profile_picture,general',
            ],
            'description' => [
                'sometimes',
                'string',
                'max:500',
            ],
            'is_public' => [
                'sometimes',
                'boolean',
            ],
        ];
    }

    /**
     * Custom error messages in French.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.in' => 'La catégorie sélectionnée est invalide',
            'description.max' => 'La description ne doit pas dépasser 500 caractères',
            'is_public.boolean' => 'Le champ visibilité doit être un booléen',
        ];
    }
}
