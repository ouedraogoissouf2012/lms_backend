<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DatesOrdonneesDeLaPeriode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Modifier une Période (#1651) : son libellé et ses cinq dates, d'un bloc.
 *
 * Les cinq dates sont PRÉSENTES dans le corps, éventuellement nulles : un `PUT` remplace la
 * représentation. Une date omise serait comparée à la valeur stockée par la seule base, et un
 * désordre remonterait en 500 au lieu d'un 422 lisible.
 *
 * `status`, `program_id` et `institution_id` n'ont aucune règle : `validated()` les écarte. Le
 * statut avance par des transitions nommées ; le Programme et l'école ne changent pas.
 */
final class UpdateTrainingSessionRequest extends FormRequest
{
    use DatesOrdonneesDeLaPeriode;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['libelle' => ['required', 'string', 'max:191']] + $this->reglesDesDates(['present', 'nullable']);
    }

    /** @return array{libelle: string, enrollment_opens_at: ?string, enrollment_closes_at: ?string, starts_on: ?string, ends_on: ?string, certificate_available_at: ?string} */
    public function modifications(): array
    {
        $valide = $this->validated();
        $texte = static fn (mixed $valeur): ?string => is_string($valeur) && $valeur !== '' ? $valeur : null;

        return [
            'libelle' => is_string($valide['libelle'] ?? null) ? $valide['libelle'] : '',
            'enrollment_opens_at' => $texte($valide['enrollment_opens_at'] ?? null),
            'enrollment_closes_at' => $texte($valide['enrollment_closes_at'] ?? null),
            'starts_on' => $texte($valide['starts_on'] ?? null),
            'ends_on' => $texte($valide['ends_on'] ?? null),
            'certificate_available_at' => $texte($valide['certificate_available_at'] ?? null),
        ];
    }
}
