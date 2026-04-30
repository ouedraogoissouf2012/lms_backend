<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file deletion requests (DELETE /api/files/{id}).
 *
 * ## Purpose
 * Authorize deletion of a file.
 * No input validation required — only authorization checks.
 * Extracted from inline checks in FileController::destroy.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is owner of file OR admin
 * 3. File must exist
 *
 * If ANY check fails → 403/404
 *
 * Deletion: Soft delete via Model::delete() (keeps historical record).
 */
final class DeleteFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Check 1: User must be authenticated
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        // Check 2: User must own the file OR be admin
        $fileId = $this->route('id');
        $file = \App\Models\File::find($fileId);

        if (!$file) {
            return false;
        }

        if (!$user->isAdmin() && $file->user_id !== $user->id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        // No input validation for DELETE with no body
        return [];
    }
}
