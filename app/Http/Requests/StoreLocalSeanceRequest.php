<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreLocalSeanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titre' => 'required|string|min:3|max:255',
            'date_seance' => 'required|date',
            'matiere_nom' => 'nullable|string|max:191',
            'classe_nom' => 'nullable|string|max:191',
            // Aucune regle `exists` : elle validerait l existence GLOBALE et
            // laisserait passer la classe d une AUTRE ecole. L appartenance est
            // verifiee par LocalSeanceCreator, qui borne sur institution_id.
            'classe_id' => 'sometimes|nullable|integer|min:1',
        ];
    }
}
