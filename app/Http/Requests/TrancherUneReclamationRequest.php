<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #1678 : `POST /api/evaluations/{id}/reclamations/{reclamation}/decision` — la gestion tranche.
 * Qui : l'admin ou le coordinateur de l'école de l'évaluation (la lecture de ses notes, réservée à
 * la gestion) ; jamais l'enseignant, qui a donné son avis. Hors de l'école : 404.
 */
final class TrancherUneReclamationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        $acteur = $this->user();

        return $this->checkEvaluationReadAccess() && $acteur instanceof User && $acteur->isManager();
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'decision' => 'required|in:maintenue,corrigee',
            'note_decidee' => 'required_if:decision,corrigee|nullable|numeric|min:0|max:20',
            'commentaire' => 'nullable|string|max:2000',
        ];
    }
}
