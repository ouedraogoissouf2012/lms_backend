<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates file update requests (PUT /api/files/{id}).
 *
 * ## Purpose
 * Update file metadata (category, description, visibility).
 * No input validation beyond field constraints.
 * Extracted from inline checks in FileController::update.
 *
 * ## Authorization Model
 * 1. User authenticated (via auth:sanctum middleware)
 * 2. User is owner of file OR admin
 * 3. File must exist
 *
 * If ANY check fails → 403/404
 *
 * Lookup: File::find($id) for seance/lesson context.
 */
final class UpdateFileRequest extends FormRequest
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
        return [
            'category' => [
                'sometimes',
                'string',
                'max:100',
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
}
