<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\StatutDuDossierEleve;
use App\Models\DossierEleve;
use App\Support\Identite\Pays;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates `PUT /api/eleves/{eleve}/dossier` (#1657). Le rôle est borné par la route (`gestion`) ; l'école et l'élève
 * par `DossierDeLEleve`. Seuls les champs utiles (loi 2013-450) : la liste blanche, c'est la validation.
 *
 * Un matricule SAISI doit être unique dans l'école de l'acteur, hors le dossier de cet élève ; absent, il est généré à
 * la création et gardé ensuite.
 */
final class EcrireDossierRequest extends FormRequest
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
        $ecole = $this->user()?->institution_id;

        return [
            'matricule' => [
                'sometimes', 'string', 'max:40',
                Rule::unique('dossiers_eleves', 'matricule')
                    ->where('institution_id', is_int($ecole) ? $ecole : 0)
                    ->whereNot('user_id', (int) $this->route('eleve')),
            ],
            'nom' => ['required', 'string', 'max:100'],
            'prenoms' => ['required', 'string', 'max:150'],
            'genre' => ['sometimes', 'nullable', Rule::in(DossierEleve::GENRES)],
            'date_naissance' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'lieu_naissance' => ['sometimes', 'nullable', 'string', 'max:100'],
            'nationalite' => ['sometimes', 'nullable', Rule::in(Pays::CODES)],
            'adresse' => ['sometimes', 'nullable', 'string', 'max:255'],
            'ville' => ['sometimes', 'nullable', 'string', 'max:100'],
            'commune' => ['sometimes', 'nullable', 'string', 'max:100'],
            'statut' => ['sometimes', Rule::enum(StatutDuDossierEleve::class)],
            'confirmer_doublon' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * L'identité à écrire, sans le drapeau de confirmation.
     *
     * @return array<string, mixed>
     */
    public function identite(): array
    {
        $valeurs = $this->safe()->except(['confirmer_doublon']);
        foreach (['nom', 'prenoms'] as $nom) {
            if (is_string($valeurs[$nom] ?? null)) {
                $valeurs[$nom] = trim($valeurs[$nom]);
            }
        }

        return $valeurs;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'matricule.unique' => 'Ce matricule est déjà celui d\'un autre élève de l\'établissement.',
            'date_naissance.before' => 'La date de naissance doit être passée.',
            'nationalite.in' => 'Nationalité inconnue (code pays ISO à deux lettres).',
        ];
    }
}
