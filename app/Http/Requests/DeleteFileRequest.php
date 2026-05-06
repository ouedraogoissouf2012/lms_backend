<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file deletion requests (DELETE /api/files/{id}).
 *
 * ## Purpose
 * Authorize file deletion before removing from database.
 * No input validation required — only authorization.
 * Prevents unauthorized file deletion across tenants.
 *
 * ## Authorization Model
 * File owner OR admin can delete.
 * Ownership check: file->user_id === auth()->id() OR isAdmin()
 * File must exist (returns 403 if not found to prevent existence leakage).
 *
 * ## Deletion Behavior
 * Soft delete via Model::delete() preserves historical record.
 * File::boot() hook auto-deletes physical file on force delete.
 *
 * ## 10-year consideration
 * Soft deletes allow recovery and audit trails.
 * Force delete (hard delete) triggers physical file removal.
 * Performance: File::find() in authorize() acceptable (single indexed lookup).
 */
final class DeleteFileRequest extends FormRequest
{
    /**
     * Verify file ownership before allowing deletion.
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
     * Get validation rules for file deletion.
     *
     * @return array
     */
    public function rules(): array
    {
        // No input validation for DELETE request with no body
        return [];
    }
}
