<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rattacher une matière à une classe, et y désigner un formateur (#848).
 *
 * Aucune règle `exists` ici, délibérément : l'appartenance à l'établissement est
 * vérifiée par le service, qui borne explicitement sur `institution_id`. Un
 * `exists:matieres,id` validerait l'existence GLOBALE et laisserait passer la
 * matière d'une autre école — le message de validation dirait alors qu'elle
 * existe, ce qui renseigne déjà un voisin.
 */
final class LinkClasseMatiereRequest extends FormRequest
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
            'matiere_id' => ['required', 'integer', 'min:1'],
            'enseignant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function matiereId(): int
    {
        return $this->integer('matiere_id');
    }

    public function enseignantId(): ?int
    {
        $id = $this->integer('enseignant_id');

        return $id > 0 ? $id : null;
    }
}
