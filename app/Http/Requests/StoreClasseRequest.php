<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Catalogue\CapaciteDeClasse;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Créer une classe locale (#848).
 *
 * `authorize()` rend `true` : l'accès est gardé par la route
 * (`role:coordinateur,admin,superAdmin`) et le DROIT d'écrire un catalogue local
 * l'est par `CatalogueAuthority`, consultée au point d'écriture. Le dupliquer ici
 * ferait deux sources de vérité pour une même décision.
 *
 * L'unicité du code n'est PAS validée ici mais par la contrainte de table : la
 * vérifier en amont ouvrirait une fenêtre entre le contrôle et l'écriture, que
 * deux requêtes concurrentes franchiraient toutes les deux.
 */
final class StoreClasseRequest extends FormRequest
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
            'libelle' => ['required', 'string', 'max:191'],
            'code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            // La CAPACITÉ de la classe (#1115, #1358) : facultative, et vide (`null` ou 0)
            // veut dire sans limite. `CapaciteDeClasse` garde les mêmes bornes.
            'effectif' => ['sometimes', 'integer', 'min:0', 'max:'.CapaciteDeClasse::MAXIMUM],
            // L'existence et l'état de la Période sont vérifiés au point d'écriture,
            // dans l'établissement (#1208) : un `exists` ici ne lirait pas l'école.
            'training_session_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{libelle: string, code: string|null, description: string|null, effectif: int, training_session_id: int|null}
     */
    public function donnees(): array
    {
        return [
            'libelle' => (string) $this->string('libelle'),
            'code' => $this->has('code') ? (string) $this->string('code') : null,
            'description' => $this->has('description') ? (string) $this->string('description') : null,
            'effectif' => $this->integer('effectif', 0),
            'training_session_id' => $this->filled('training_session_id') ? $this->integer('training_session_id') : null,
        ];
    }
}
