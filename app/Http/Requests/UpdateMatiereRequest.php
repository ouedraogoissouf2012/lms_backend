<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifier une matière locale (#1653) : un PATCH, chaque champ facultatif, et seul un champ
 * ENVOYÉ change. Mêmes bornes que la création (`StoreMatiereRequest`).
 *
 * `authorize()` rend `true`, comme à la création : la route porte la garde de rôle, et la porte
 * du catalogue local est consultée au point d'écriture (`LocalMatiereEditor`).
 *
 * Le code envoyé vide arrive NULL (`ConvertEmptyStringsToNull`) et EFFACE le code ; son
 * unicité est tranchée par la base au point d'écriture, pas par un SELECT ici.
 */
final class UpdateMatiereRequest extends FormRequest
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
            'libelle' => ['sometimes', 'required', 'string', 'max:191'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'coefficient' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'credit' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * Les seuls champs envoyés, typés.
     *
     * @return array{libelle?: string, code?: string|null, description?: string|null, coefficient?: int, credit?: int}
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
        foreach (['coefficient', 'credit'] as $champ) {
            if ($this->has($champ)) {
                $modifications[$champ] = $this->integer($champ);
            }
        }

        return $modifications;
    }
}
