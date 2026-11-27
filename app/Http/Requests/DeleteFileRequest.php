<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Services\File\LecteurDeFichier;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file deletion requests (DELETE /api/files/{id}).
 *
 * ## Purpose
 * Authorize file deletion with ownership + tenant isolation.
 * No input validation required — only authorization.
 * Prevents unauthorized file deletion across tenants.
 *
 * ## Authorization Model (issue #102 fix)
 *
 * La règle de {@see LecteurDeFichier} (#1768), via `ChecksFileAuthorization`,
 * la même que `UpdateFileRequest` : le propriétaire, ou la gestion de son école ;
 * un fichier que l'appelant ne lit pas répond 404, comme un id absent.
 *
 * Previous version used `$user->isAdmin()` which ambiguously allowed any admin
 * (including intra-tenant ones) to delete files cross-tenant (HIGH IDOR).
 *
 * ## Deletion Behavior
 * Soft delete via Model::delete() preserves historical record.
 * File::boot() hook auto-deletes physical file on force delete.
 */
final class DeleteFileRequest extends FormRequest
{
    use ChecksFileAuthorization;

    /**
     * Verify file ownership + tenant isolation before allowing deletion.
     */
    public function authorize(LecteurDeFichier $lecteur): bool
    {
        return $this->autoriserLaModeration($lecteur, $this->route('file'), $this->user());
    }

    /**
     * Get validation rules for file deletion.
     *
     * @return array<string, string>
     */
    public function rules(): array
    {
        // No input validation for DELETE request with no body
        return [];
    }
}
