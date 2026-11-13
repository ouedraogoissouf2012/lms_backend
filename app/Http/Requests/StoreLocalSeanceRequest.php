<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLocalSeanceRequest extends FormRequest
{
    /**
     * ISO 8601 avec décalage (D4), et rien d'autre : une heure seule, une date
     * sans décalage ou une date seule ne disent pas l'instant de la séance.
     */
    private const DATE_ISO_AVEC_DECALAGE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * Les rôles sont gardés UNE fois, par la route (`role:enseignant,
     * coordinateur,superAdmin`, `routes/api/lms.php`), D1. Une seconde liste ici
     * recopierait la normalisation d'`EnsureRole` et divergerait : elle admettait
     * `admin`, que la route refuse.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titre' => 'required|string|min:3|max:255',
            'date_seance' => ['required', 'date', 'regex:'.self::DATE_ISO_AVEC_DECALAGE],
            'matiere_nom' => 'nullable|string|max:191',
            'classe_nom' => 'nullable|string|max:191',
            // Aucune regle `exists` : elle validerait l existence GLOBALE et
            // laisserait passer la classe d une AUTRE ecole. L appartenance est
            // verifiee par LocalSeanceCreator, qui borne sur institution_id.
            'classe_id' => 'sometimes|nullable|integer|min:1',
            // Même motif : l'appartenance et le programme sont vérifiés par LocalSeanceCreator.
            'matiere_id' => 'sometimes|nullable|integer|min:1',
        ];
    }
}
