<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PorteLaStructureLocale;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifier une classe locale (#1652) : un PATCH, chaque champ facultatif, et seul un champ ENVOYÉ
 * change. Mêmes bornes que la création (`StoreClasseRequest`).
 *
 * `authorize()` rend `true`, comme à la création : la route porte la garde de rôle, et la porte du
 * catalogue local est consultée au point d'écriture (`LocalClasseEditor`). Un code envoyé vide arrive
 * NULL (`ConvertEmptyStringsToNull`) et EFFACE le code ; son unicité est tranchée par la base.
 */
final class UpdateClasseRequest extends FormRequest
{
    use PorteLaStructureLocale;

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
            'libelle' => ['sometimes', 'required', 'string', 'max:191'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...$this->reglesDeLaStructure(),
        ];
    }

    /**
     * Les seuls champs envoyés, typés.
     *
     * @return array{libelle?: string, code?: string|null, description?: string|null, filiere_id?: int|null, niveau_id?: int|null}
     */
    public function modifications(): array
    {
        $modifications = [];

        if ($this->has('libelle')) {
            $modifications['libelle'] = (string) $this->string('libelle');
        }
        foreach (['code', 'description'] as $champ) {
            if ($this->has($champ)) {
                $modifications[$champ] = $this->input($champ) === null ? null : (string) $this->string($champ);
            }
        }

        return $modifications + $this->structure();
    }
}
