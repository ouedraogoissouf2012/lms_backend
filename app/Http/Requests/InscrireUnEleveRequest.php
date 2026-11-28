<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/classes/{classe}/eleves` (#1619) : l'élève existant à inscrire, par son id LOCAL. Qu'il soit un
 * élève de l'école se juge au service (404 d'ailleurs, 422 s'il n'est pas un élève).
 */
final class InscrireUnEleveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'eleve_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function eleve(): int
    {
        return $this->integer('eleve_id');
    }
}
