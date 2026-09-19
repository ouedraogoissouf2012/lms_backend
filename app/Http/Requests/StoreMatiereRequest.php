<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Créer une matière locale (#797, #848).
 *
 * `authorize()` rend `true` : la route porte la garde de rôle et
 * `CatalogueAuthority` porte le DROIT d'écrire un catalogue local, consultée au
 * point d'écriture. Le dupliquer ici ferait deux sources de vérité.
 *
 * `coefficient` et `credit` ont une valeur par défaut EN BASE. Les rendre
 * facultatifs ici évite d'imposer à l'écran une décision que le métier n'a pas
 * encore prise — une matière se crée souvent avant d'être pondérée.
 */
final class StoreMatiereRequest extends FormRequest
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
            'coefficient' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'credit' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ];
    }

    /**
     * @return array{libelle: string, code: string|null, description: string|null, coefficient: int, credit: int}
     */
    public function donnees(): array
    {
        return [
            'libelle' => (string) $this->string('libelle'),
            'code' => $this->has('code') ? (string) $this->string('code') : null,
            'description' => $this->has('description') ? (string) $this->string('description') : null,
            'coefficient' => $this->integer('coefficient', 1),
            'credit' => $this->integer('credit', 1),
        ];
    }
}
