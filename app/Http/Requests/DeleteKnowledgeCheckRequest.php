<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates knowledge check deletion (DELETE /api/knowledge-checks/{id}).
 *
 * ## Purpose
 * Delete a knowledge check quiz (test yourself).
 * Only creator or admin can delete.
 *
 * ## Authorization Model
 * Creator (via chapter->enseignant_id) OR admin.
 * Returns 403 if not authorized.
 *
 * ## 10-year Consideration
 * Soft deletes preserve audit trail and student attempt history.
 * Deleting quiz does not delete student attempts (cascade: soft).
 */
final class DeleteKnowledgeCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = auth()->user();
        if (!$user) {
            return false;
        }

        $quiz = $this->route('knowledgeCheck');
        if (!$quiz) {
            return false;
        }

        return $user->isAdmin() || $quiz->chapter->enseignant_id === $user->id;
    }

    public function rules(): array
    {
        return [];
    }
}
