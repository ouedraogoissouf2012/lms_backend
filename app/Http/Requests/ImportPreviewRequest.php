<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImportPreviewRequest extends FormRequest
{
    /**
     * Séparateurs acceptés, désignés par un NOM.
     *
     * Le client envoie « tab » et non une tabulation : `TrimStrings` élague les
     * blancs de toute valeur d'entrée, si bien qu'une tabulation arrivait vide
     * et faisait échouer la validation — donc tout fichier tabulé. Mesuré : 302
     * au lieu de 200. Un nom traverse le transport sans être altéré.
     *
     * @var array<string, string>
     */
    private const DELIMITERS = [
        'semicolon' => ';',
        'comma' => ',',
        'tab' => "\t",
    ];

    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && ($user->isTeacher() || $user->isCoordinator() || $user->isAdmin());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'mapping' => ['sometimes', 'array'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            'delimiter' => ['sometimes', 'string', Rule::in(array_keys(self::DELIMITERS))],
        ];
    }

    /**
     * Cartographie brute déclarée par le client. Le filtrage sur les champs
     * réellement lus appartient à `ColumnMap`, pas à la couche HTTP.
     *
     * @return array<array-key, mixed>
     */
    public function mappingInput(): array
    {
        $mapping = $this->input('mapping');

        return is_array($mapping) ? $mapping : [];
    }

    /**
     * `null` = « détecte toi-même », ce qui reste le comportement d'un client
     * qui ne déclare rien.
     */
    public function delimiterInput(): ?string
    {
        $name = $this->input('delimiter');

        return is_string($name) ? (self::DELIMITERS[$name] ?? null) : null;
    }
}
