<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Http\FormRequest;

/**
 * #1676 : la période du bilan d'une classe, `?du=&au=` sur la date d'évaluation, bornes incluses.
 * Sans borne, l'année entière. Les sous-périodes (S1, S2) se traduiront en dates quand le lot
 * Structure les créera : cette porte reste la seule entrée.
 *
 * L'accès (qui voit quelle classe, et quoi) est décidé par `AccesAuBilanDeClasse`, pas ici.
 */
final class VoirLeBilanDeClasseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return [
            'du' => 'nullable|date_format:Y-m-d',
            'au' => 'nullable|date_format:Y-m-d|after_or_equal:du',
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'du.date_format' => 'La date de début doit être au format AAAA-MM-JJ.',
            'au.date_format' => 'La date de fin doit être au format AAAA-MM-JJ.',
            'au.after_or_equal' => 'La date de fin doit être égale ou postérieure à la date de début.',
        ];
    }

    public function du(): ?CarbonInterface
    {
        return $this->borne('du');
    }

    public function au(): ?CarbonInterface
    {
        return $this->borne('au');
    }

    private function borne(string $cle): ?CarbonInterface
    {
        $valeur = $this->validated($cle);

        return is_string($valeur) && $valeur !== '' ? CarbonImmutable::createFromFormat('Y-m-d', $valeur)?->startOfDay() : null;
    }
}
