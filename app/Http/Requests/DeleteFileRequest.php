<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file deletion requests (DELETE /api/files/{file}).
 *
 * ## Purpose
 * Authorize file deletion before removing from database via implicit model binding.
 * No input validation required — only authorization.
 * Prevents unauthorized file deletion across tenants.
 *
 * ## Authorization Model
 * File owner OR admin can delete.
 * Ownership check: $file->user_id === auth()->id() OR isAdmin()
 * File existence: guaranteed by implicit route model binding {file} → 404 if not found.
 *
 * ## Deletion Behavior
 * Soft delete via Model::delete() preserves historical record.
 * File::boot() hook auto-deletes physical file on force delete.
 *
 * ## 10-year consideration
 * Soft deletes allow recovery and audit trails.
 * Force delete (hard delete) triggers physical file removal.
 * Performance: Implicit model binding eliminates redundant File::find() call.
 */
final class DeleteFileRequest extends FormRequest
{
    /**
     * Verify file ownership before allowing deletion.
     * File is already resolved by implicit route model binding {file}.
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
