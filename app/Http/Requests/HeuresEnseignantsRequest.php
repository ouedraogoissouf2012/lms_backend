<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Attendances\Heures\FiltresDHeures;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * `GET /lms/heures-enseignants` (#1682) : la plage (obligatoire, une année au plus), les filtres, le format (`csv`
 * pour l'export). Aucune règle `exists` : elle validerait l'existence GLOBALE ; un id d'une autre école ne trouve
 * simplement aucune séance.
 */
final class HeuresEnseignantsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'du' => ['required', 'date_format:Y-m-d'],
            'au' => ['required', 'date_format:Y-m-d', 'after_or_equal:du', 'before_or_equal:'.$this->plafond()],
            'enseignant_id' => ['nullable', 'integer', 'min:1'],
            'classe_id' => ['nullable', 'integer', 'min:1'],
            'matiere_id' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'in:csv'],
        ];
    }

    public function filtres(): FiltresDHeures
    {
        return new FiltresDHeures(
            du: $this->string('du')->toString(),
            au: $this->string('au')->toString(),
            enseignantId: $this->filled('enseignant_id') ? $this->integer('enseignant_id') : null,
            classeId: $this->filled('classe_id') ? $this->integer('classe_id') : null,
            matiereId: $this->filled('matiere_id') ? $this->integer('matiere_id') : null,
        );
    }

    public function enCsv(): bool
    {
        return $this->string('format')->toString() === 'csv';
    }

    /** Une année au plus après `du` : le rapport lit chaque séance de la plage. */
    private function plafond(): string
    {
        $du = $this->string('du')->toString();

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) === 1 ? Carbon::parse($du)->addYear()->toDateString() : '9999-12-31';
    }
}
