<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Export\FormatDExport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Le format d'un export (#1656), EXIGÉ : `csv` ou `pdf`. Un format absent ou inconnu est refusé (422), jamais
 * remplacé par un défaut : l'école recevrait un fichier qu'elle n'a pas demandé.
 *
 * `authorize()` rend `true` : le rôle est vérifié par la route (`personnel`), la portée par le lecteur.
 */
final class ExporterUneListeRequest extends FormRequest
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
        return ['format' => ['required', 'string', Rule::enum(FormatDExport::class)]];
    }

    /** `format()` est déjà une méthode de Request (le format de la réponse) : ne pas la masquer. */
    public function formatDExport(): FormatDExport
    {
        return FormatDExport::from($this->string('format')->toString());
    }
}
