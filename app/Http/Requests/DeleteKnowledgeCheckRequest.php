<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ReservesLessonContentToTeacher;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Supprimer un quiz de chapitre (DELETE /api/knowledge-checks/{id}).
 *
 * L'enseignant seul (#1167) : la gestion consulte, et son refus porte un motif,
 * comme à la création et à la modification. L'auteur du chapitre est vérifié dans
 * KnowledgeCheckCrudService.
 */
final class DeleteKnowledgeCheckRequest extends FormRequest
{
    use ReservesLessonContentToTeacher;

    public function authorize(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isTeacher();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
