<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DatesOrdonneesDeLaPeriode;
use App\Models\Program;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Création d'une Période (#827).
 *
 * ## Ce qui n'est PAS validé, et c'est délibéré
 *
 * `status` et `institution_id` n'ont aucune règle : les omettre les fait
 * rejeter par `validated()`. Une Période naît **brouillon** — publier est une
 * décision distincte de créer — et son établissement vient du tenant résolu,
 * jamais du client.
 *
 * ## Le Programme doit appartenir à l'établissement du demandeur
 *
 * `Rule::exists` est borné par `institution_id` : sans ce scope, un
 * coordinateur pourrait rattacher sa Période au Programme d'une autre école, et
 * le simple message d'erreur révélerait quels identifiants existent ailleurs.
 *
 * ## L'ordre des dates est vérifié ICI aussi
 *
 * La base porte la contrainte de dernier recours ; sans cette validation, une
 * saisie désordonnée remonterait en 500 au lieu d'un 422 lisible. Chaque date
 * est comparée à la PRÉCÉDENTE RENSEIGNÉE : comparer seulement à la voisine
 * immédiate laisserait passer `opens > starts` dès que `closes` est absente —
 * même raisonnement que le `COALESCE` de la migration.
 */
final class StoreTrainingSessionRequest extends FormRequest
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
        $regles = [
            'program_id' => [
                'required',
                'integer',
                Rule::exists(Program::class, 'id')
                    ->where('institution_id', $this->user()?->institution_id)
                    ->whereNull('deleted_at'),
            ],
            'libelle' => ['required', 'string', 'max:191'],
            'min_enrollments' => ['nullable', 'integer', 'min:1'],
            // Entier : la plus petite unité monétaire, jamais un flottant.
            'tarif' => ['nullable', 'integer', 'min:0'],
            'devise' => ['nullable', 'string', 'size:3'],
            'heures_stagiaires' => ['nullable', 'integer', 'min:0'],
        ];

        $regles += $this->reglesDesDates(['nullable']);

        return $regles;
    }
}
