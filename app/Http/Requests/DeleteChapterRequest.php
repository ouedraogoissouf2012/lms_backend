<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksChapterOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates chapter delete request (DELETE /api/chapters/{id}).
 *
 * ## Purpose
 * Authorize deletion of chapter.
 * Chapter belongs to lesson → authorize via lesson ownership.
 * No input fields required.
 *
 * ## Authorization Model
 * Only lesson owner or admin can delete:
 * 1. User authenticated
 * 2. User is teacher/coordinator/admin
 * 3. Chapter's lesson owner == user OR is admin
 * 4. Chapter belongs to user's institution (multi-tenant)
 */
final class DeleteChapterRequest extends FormRequest
{
    use ChecksChapterOwnership;

    public function authorize(): bool
    {
        return $this->chapterOwnershipPasses();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
