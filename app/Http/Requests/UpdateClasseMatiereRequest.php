<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifier une matière au programme d'une classe (#1653) : son formateur et son coefficient
 * propre à la classe. Un PATCH : seul un champ envoyé change, et nul est une valeur (aucun
 * formateur ; le coefficient de la matière s'applique).
 *
 * Aucune règle `exists`, comme au rattachement (`LinkClasseMatiereRequest`) : l'appartenance du
 * formateur à l'établissement est vérifiée par le service, borné sur `institution_id`.
 */
final class UpdateClasseMatiereRequest extends FormRequest
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
            'enseignant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'coefficient' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
        ];
    }

    /** @return array{enseignant_id?: int|null, coefficient?: int|null} */
    public function modifications(): array
    {
        $modifications = [];
        foreach (['enseignant_id', 'coefficient'] as $champ) {
            if ($this->has($champ)) {
                $modifications[$champ] = $this->input($champ) === null ? null : $this->integer($champ);
            }
        }

        return $modifications;
    }
}
