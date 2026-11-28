<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\EtatJustificatif;
use App\Services\Attendances\Justification\FiltresDeJustificatifs;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `GET /lms/justificatifs` (#1681) : les filtres de la gestion (état, élève, dates de dépôt, page). */
final class ListerJustificatifsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'etat' => ['nullable', Rule::enum(EtatJustificatif::class)],
            'eleve_id' => ['nullable', 'integer', 'min:1'],
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filtres(): FiltresDeJustificatifs
    {
        return new FiltresDeJustificatifs(
            etat: $this->filled('etat') ? EtatJustificatif::from($this->string('etat')->toString()) : null,
            eleveId: $this->filled('eleve_id') ? $this->integer('eleve_id') : null,
            du: $this->filled('du') ? $this->string('du')->toString() : null,
            au: $this->filled('au') ? $this->string('au')->toString() : null,
            page: $this->filled('page') ? $this->integer('page') : 1,
        );
    }
}
