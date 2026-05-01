<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file metadata update requests (PUT /api/files/{file}).
 *
 * ## Purpose
 * Update file metadata while enforcing ownership via implicit model binding.
 * Updatable: category, description, visibility.
 * Prevents unauthorized file metadata hijacking.
 *
 * ## Updatable Fields
 * - category: document classification (course_material, assignment, resource, other, forum_attachment, profile_picture, general)
 * - description: optional file description (max 500 chars)
 * - is_public: visibility toggle (boolean)
 *
 * ## Authorization Model
 * File owner OR admin can update.
 * Ownership check: $file->user_id === auth()->id() OR isAdmin()
 * File existence: guaranteed by implicit route model binding {file} → 404 if not found.
 *
 * ## 10-year consideration
 * Enum values (category) must match File model fillable + UploadFileRequest rules.
 * If categories change, update: rules(), messages(), and UploadFileRequest together.
 *
 * Performance: Implicit model binding eliminates redundant File::find() call.
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
        $file = $this->route('file');

        if (!$user || !$file) {
            return false;
        }

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
