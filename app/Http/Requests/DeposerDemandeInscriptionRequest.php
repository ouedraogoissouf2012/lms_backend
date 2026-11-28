<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DemandeInscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `POST /api/demandes-inscription` (#1659), la porte PUBLIQUE. Seuls les champs utiles (loi 2013-450) ; le
 * consentement est obligatoire. Rien ici ne dépend de l'école désignée : un 422 ne dit pas si elle existe.
 */
final class DeposerDemandeInscriptionRequest extends FormRequest
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
            'ecole' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(DemandeInscription::TYPES)],
            'nom' => ['required', 'string', 'max:100'],
            'prenoms' => ['required', 'string', 'max:150'],
            'date_naissance' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'genre' => ['sometimes', 'nullable', Rule::in(['F', 'M'])],
            'declarant_nom' => ['required', 'string', 'max:150'],
            'declarant_relation' => ['required', Rule::in(DemandeInscription::RELATIONS)],
            'declarant_telephone' => ['required_without:declarant_courriel', 'nullable', 'string', 'max:30'],
            'declarant_courriel' => ['required_without:declarant_telephone', 'nullable', 'email', 'max:255'],
            'classe_souhaitee' => ['sometimes', 'nullable', 'string', 'max:100'],
            'diplome_entree' => ['sometimes', 'nullable', 'string', 'max:100'],
            'consentement' => ['accepted'],
        ];
    }

    /**
     * Les champs de la demande, sans l'école ni le drapeau de consentement (qui devient un horodatage).
     *
     * @return array<string, mixed>
     */
    public function demande(): array
    {
        return $this->safe()->except(['ecole', 'consentement']);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'consentement.accepted' => 'Votre accord pour que l\'établissement traite ces informations est nécessaire.',
            'declarant_telephone.required_without' => 'Indiquez un téléphone ou un courriel pour être recontacté.',
            'declarant_courriel.required_without' => 'Indiquez un téléphone ou un courriel pour être recontacté.',
        ];
    }
}
