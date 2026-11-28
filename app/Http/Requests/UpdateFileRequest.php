<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Services\File\LecteurDeFichier;
use Illuminate\Foundation\Http\FormRequest;

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
 * La règle de {@see LecteurDeFichier} (#1768), via `ChecksFileAuthorization` :
 * le propriétaire, ou la gestion de son école ; un fichier que l'appelant ne lit
 * pas répond 404, comme un id absent.
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
    public function authorize(LecteurDeFichier $lecteur): bool
    {
        return $this->autoriserLaModeration($lecteur, $this->route('file'), $this->user());
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
