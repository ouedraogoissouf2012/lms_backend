<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksChapterOwnership;
use App\Services\Search\TeacherOwnershipScope;
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
 * Only the teacher who owns the lesson can delete (#1167, the management only reads):
 * 1. User authenticated
 * 2. User is a teacher
 * 3. Chapter's lesson owner == user
 * 4. Chapter belongs to user's institution (multi-tenant)
 */
final class DeleteChapterRequest extends FormRequest
{
    use ChecksChapterOwnership;

    public function authorize(TeacherOwnershipScope $auteurs): bool
    {
        return $this->chapterOwnershipPasses($auteurs);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
