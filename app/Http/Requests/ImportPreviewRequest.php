<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ImportPreviewRequest extends FormRequest
{
    /**
     * Séparateurs acceptés. Liste fermée : le client dit avec quel séparateur il
     * a montré les colonnes à l'utilisateur, il ne choisit pas un comportement
     * d'analyse arbitraire.
     *
     * @var list<string>
     */
    private const DELIMITERS = [';', ',', "\t"];

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
            'delimiter' => ['sometimes', 'string', Rule::in(self::DELIMITERS)],
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
        $delimiter = $this->input('delimiter');

        return is_string($delimiter) && in_array($delimiter, self::DELIMITERS, true)
            ? $delimiter
            : null;
    }
}
