<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksFileAuthorization;
use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

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
 * Strict tenant isolation via `ChecksFileAuthorization::canModerateFile()`.
 * Same logic as `UpdateFileRequest`: supradmin bypass / tenant match /
 * owner / admin intra-tenant.
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
