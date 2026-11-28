<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChecksEvaluationOwnership;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #1678 : `POST /api/evaluations/{id}/reclamations/{reclamation}/avis` — l'avis de l'enseignant.
 * Qui : la règle de modification de l'évaluation ({@see ChecksEvaluationOwnership}) : son auteur,
 * ou l'admin de l'école ; jamais le coordinateur, qui tranche. Hors de l'école : 404.
 */
final class DonnerUnAvisSurUneReclamationRequest extends FormRequest
{
    use ChecksEvaluationOwnership;

    public function authorize(): bool
    {
        return $this->checkEvaluationOwnership();
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'avis' => 'required|in:maintenir,corriger',
            'commentaire' => 'nullable|string|max:2000',
            'note_proposee' => 'required_if:avis,corriger|nullable|numeric|min:0|max:20',
        ];
    }
}
