<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST` et `PUT /lms/emplois-du-temps[/{id}]` (#1669) : la classe (à la création), le titre, la plage, l'état en vigueur.
 * Aucune règle `exists` sur la classe : elle validerait l'existence GLOBALE ; le service la cherche dans l'école (404).
 * La plage reste dans la période de la classe, vérifiée par le service (422 `emploi_du_temps_hors_periode`).
 */
final class EmploiDuTempsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'classe_id' => [$this->isMethod('post') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'titre' => ['required', 'string', 'max:150'],
            'du' => ['required', 'date_format:Y-m-d'],
            'au' => ['required', 'date_format:Y-m-d', 'after_or_equal:du'],
            'en_vigueur' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{titre: string, du: string, au: string, en_vigueur: bool} */
    public function champs(): array
    {
        return [
            'titre' => $this->string('titre')->trim()->toString(),
            'du' => $this->string('du')->toString(),
            'au' => $this->string('au')->toString(),
            'en_vigueur' => $this->has('en_vigueur') ? $this->boolean('en_vigueur') : true,
        ];
    }

    public function classeId(): int
    {
        return $this->integer('classe_id');
    }
}
