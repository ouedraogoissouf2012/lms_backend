<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TypeDeSeance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `PUT /lms/emplois-du-temps/{id}/lignes` (#1669) : la semaine type, remplacée d'un bloc. Une ligne de COURS porte une
 * matière (au programme, vérifié par le service) et, au choix, un enseignant et un type ; une PAUSE porte un libellé et
 * aucune matière. `jour` suit l'ISO (1 = lundi … 7 = dimanche) ; `debut` < `fin`, au format HH:MM.
 */
final class LignesEmploiDuTempsRequest extends FormRequest
{
    public const LIGNES_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lignes' => ['present', 'array', 'max:'.self::LIGNES_MAX],
            'lignes.*.jour' => ['required', 'integer', 'between:1,7'],
            'lignes.*.debut' => ['required', 'date_format:H:i'],
            'lignes.*.fin' => ['required', 'date_format:H:i', 'after:lignes.*.debut'],
            'lignes.*.matiere_id' => ['nullable', 'integer', 'min:1', 'required_without:lignes.*.libelle'],
            'lignes.*.enseignant_id' => ['nullable', 'integer', 'min:1'],
            'lignes.*.type_seance' => ['nullable', Rule::enum(TypeDeSeance::class)],
            'lignes.*.libelle' => ['nullable', 'string', 'max:100', 'required_without:lignes.*.matiere_id'],
        ];
    }

    /** @return list<array{jour: int, debut: string, fin: string, matiere_id: ?int, enseignant_id: ?int, type_seance: ?string, libelle: ?string}> */
    public function lignes(): array
    {
        $lignes = [];
        foreach ((array) $this->input('lignes', []) as $l) {
            $l = is_array($l) ? $l : [];
            $lignes[] = [
                'jour' => is_numeric($l['jour'] ?? null) ? (int) $l['jour'] : 0,
                'debut' => is_string($l['debut'] ?? null) ? $l['debut'] : '',
                'fin' => is_string($l['fin'] ?? null) ? $l['fin'] : '',
                'matiere_id' => is_numeric($l['matiere_id'] ?? null) ? (int) $l['matiere_id'] : null,
                'enseignant_id' => is_numeric($l['enseignant_id'] ?? null) ? (int) $l['enseignant_id'] : null,
                'type_seance' => is_string($l['type_seance'] ?? null) ? $l['type_seance'] : null,
                'libelle' => is_string($l['libelle'] ?? null) ? trim($l['libelle']) : null,
            ];
        }

        return $lignes;
    }
}
