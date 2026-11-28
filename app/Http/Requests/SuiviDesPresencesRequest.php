<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AttendanceMark;
use App\Services\Attendances\Suivi\FiltresDePresences;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * `GET /lms/presences` et `GET /lms/presences/synthese` (#1683) : la plage (obligatoire, une année au plus), les
 * filtres, la page, le seuil (0 à 100, 75 par défaut). Aucune règle `exists` : elle validerait l'existence GLOBALE ;
 * un id d'une autre école ne trouve simplement aucune présence.
 */
final class SuiviDesPresencesRequest extends FormRequest
{
    public const SEUIL_PAR_DEFAUT = 75;

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
            'classe_id' => ['nullable', 'integer', 'min:1'],
            'matiere_id' => ['nullable', 'integer', 'min:1'],
            'eleve_id' => ['nullable', 'integer', 'min:1'],
            'marque' => ['nullable', Rule::enum(AttendanceMark::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'seuil' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];
    }

    public function filtres(): FiltresDePresences
    {
        return new FiltresDePresences(
            du: $this->string('du')->toString(),
            au: $this->string('au')->toString(),
            classeId: $this->filled('classe_id') ? $this->integer('classe_id') : null,
            matiereId: $this->filled('matiere_id') ? $this->integer('matiere_id') : null,
            eleveId: $this->filled('eleve_id') ? $this->integer('eleve_id') : null,
            marque: $this->filled('marque') ? AttendanceMark::from($this->string('marque')->toString()) : null,
            page: $this->filled('page') ? $this->integer('page') : 1,
        );
    }

    public function seuil(): int
    {
        return $this->filled('seuil') ? $this->integer('seuil') : self::SEUIL_PAR_DEFAUT;
    }

    /** Une année au plus après `du` : chaque paire séance × élève de la plage est lue. */
    private function plafond(): string
    {
        $du = $this->string('du')->toString();

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $du) === 1 ? Carbon::parse($du)->addYear()->toDateString() : '9999-12-31';
    }
}
