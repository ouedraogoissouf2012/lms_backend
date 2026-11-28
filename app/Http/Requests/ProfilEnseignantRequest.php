<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\GradeAcademique;
use App\Enums\StatutEnseignant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /lms/enseignants/{id}/profil` (#1666) : le profil entier, chaque champ facultatif (vide : effacé). Le
 * matricule est saisi librement ; son unicité dans l'école est vérifiée par le service (422 `matricule_deja_pris`).
 */
final class ProfilEnseignantRequest extends FormRequest
{
    public const CHARGE_MAX = 60;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'matricule' => ['nullable', 'string', 'max:50'],
            'specialite' => ['nullable', 'string', 'max:150'],
            'titre' => ['nullable', 'string', 'max:50'],
            'grade' => ['nullable', Rule::enum(GradeAcademique::class)],
            'diplome' => ['nullable', 'string', 'max:150'],
            'universite' => ['nullable', 'string', 'max:150'],
            'date_debut' => ['nullable', 'date_format:Y-m-d'],
            'statut' => ['nullable', Rule::enum(StatutEnseignant::class)],
            'charge_hebdo_max' => ['nullable', 'integer', 'between:1,'.self::CHARGE_MAX],
        ];
    }

    /** @return array{matricule: ?string, specialite: ?string, titre: ?string, grade: ?string, diplome: ?string, universite: ?string, date_debut: ?string, statut: ?string, charge_hebdo_max: ?int} */
    public function champs(): array
    {
        $texte = fn (string $cle): ?string => $this->filled($cle) ? $this->string($cle)->trim()->toString() : null;

        return [
            'matricule' => $texte('matricule'),
            'specialite' => $texte('specialite'),
            'titre' => $texte('titre'),
            'grade' => $texte('grade'),
            'diplome' => $texte('diplome'),
            'universite' => $texte('universite'),
            'date_debut' => $texte('date_debut'),
            'statut' => $texte('statut'),
            'charge_hebdo_max' => $this->filled('charge_hebdo_max') ? $this->integer('charge_hebdo_max') : null,
        ];
    }
}
