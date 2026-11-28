<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Créer (POST) ou modifier (PATCH) une sous-période (#1806). À la création, libellé, début et fin sont exigés ; à la
 * modification, seuls les champs envoyés changent. Les dates sont des jours (`Y-m-d`). Quand le début et la fin
 * sont envoyés ensemble, la fin ne précède pas le début ; une fin seule est comparée au début enregistré par le
 * service. Les bornes de la période et le non-chevauchement : le service, sous verrou.
 *
 * `authorize()` rend `true` : le rôle est vérifié par le groupe de la route, le droit par la porte du catalogue.
 */
final class EcrireUneSousPeriodeRequest extends FormRequest
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
        $exige = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'libelle' => [$exige, 'string', 'max:191', 'not_regex:/^\s*$/'],
            'debut' => [$exige, 'date_format:Y-m-d'],
            'fin' => [$exige, 'date_format:Y-m-d', ...($this->filled('debut') ? ['after_or_equal:debut'] : [])],
            'ordre' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'libelle.not_regex' => 'Le libellé est obligatoire.',
            'fin.after_or_equal' => 'La fin précède le début.',
        ];
    }

    /** @return array{libelle?: string, debut?: string, fin?: string, ordre?: ?int} */
    public function donnees(): array
    {
        /** @var array{libelle?: string, debut?: string, fin?: string, ordre?: ?int} $valide */
        $valide = $this->validated();

        return $valide;
    }
}
