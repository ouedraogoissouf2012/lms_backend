<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file metadata update requests (PUT /api/files/{id}).
 *
 * ## Purpose
 * Update file metadata while enforcing ownership.
 * Updatable: category, description, visibility.
 * Prevents unauthorized file metadata hijacking.
 *
 * ## Updatable Fields
 * - category: document classification (course_material, assignment, resource, other)
 * - description: optional file description (max 500 chars)
 * - is_public: visibility toggle (boolean)
 *
 * ## Authorization Model
 * File owner OR admin can update.
 * Ownership check: file->user_id === auth()->id() OR isAdmin()
 * File must exist (returns 403 if not found to prevent existence leakage).
 *
 * ## 10-year consideration
 * Enum values (category) must match File model fillable + UploadFileRequest rules.
 * If categories change, update: rules(), messages(), and UploadFileRequest together.
 *
 * Performance: File::find() in authorize() acceptable (single lookup, indexed by id).
 */
final class UpdateFileRequest extends FormRequest
{
    /**
     * Verify file ownership before allowing update.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $file = $this->route('file');
        if (!$file) {
            return false;
        }

        // Owner OR admin
        return $file->user_id === $user->id || $user->isAdmin();
    }

    /**
     * Get validation rules for file metadata update.
     *
     * @return array
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
     * @return array
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
