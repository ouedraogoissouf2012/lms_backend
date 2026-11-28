<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `GET /api/eleves/{eleve}/export` (#1705) : le format de l'export, JSON ou PDF.
 */
final class ExporterLesDonneesRequest extends FormRequest
{
    public const FORMATS = ['json', 'pdf'];

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
            'format' => ['required', 'string', 'in:'.implode(',', self::FORMATS)],
        ];
    }

    /** Le format validé. Pas `format()` : `Request::format()` existe déjà, pour la négociation de contenu. */
    public function formatDeLExport(): string
    {
        return $this->validated('format') === 'pdf' ? 'pdf' : 'json';
    }
}
