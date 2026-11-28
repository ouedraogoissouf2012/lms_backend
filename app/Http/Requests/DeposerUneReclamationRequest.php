<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * #1678 : `POST /api/evaluations/{id}/reclamations` — l'élève conteste la note de SA copie. La
 * copie, le délai et la réclamation déjà ouverte sont vérifiés par `ReclamationsDeNotes`.
 */
final class DeposerUneReclamationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isStudent() === true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['motif' => 'required|string|min:5|max:2000'];
    }

    public function motif(): string
    {
        $motif = $this->validated('motif');

        return is_string($motif) ? $motif : '';
    }
}
