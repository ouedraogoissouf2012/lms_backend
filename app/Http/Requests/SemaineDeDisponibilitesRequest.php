<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /lms/enseignants/{id}/disponibilites` (#1666) : la semaine type, remplacée d'un bloc. Chaque créneau : jour ISO
 * (1 = lundi … 7), début < fin (HH:MM), disponible ou non. Le chevauchement est refusé par le service.
 */
final class SemaineDeDisponibilitesRequest extends FormRequest
{
    public const CRENEAUX_MAX = 100;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'semaine' => ['present', 'array', 'max:'.self::CRENEAUX_MAX],
            'semaine.*.jour' => ['required', 'integer', 'between:1,7'],
            'semaine.*.debut' => ['required', 'date_format:H:i'],
            'semaine.*.fin' => ['required', 'date_format:H:i', 'after:semaine.*.debut'],
            'semaine.*.disponible' => ['required', 'boolean'],
        ];
    }

    /** @return list<array{jour: int, debut: string, fin: string, disponible: bool}> */
    public function semaine(): array
    {
        $creneaux = [];
        foreach ((array) $this->input('semaine', []) as $c) {
            $c = is_array($c) ? $c : [];
            $creneaux[] = [
                'jour' => is_numeric($c['jour'] ?? null) ? (int) $c['jour'] : 0,
                'debut' => (is_string($c['debut'] ?? null) ? $c['debut'] : '').':00',
                'fin' => (is_string($c['fin'] ?? null) ? $c['fin'] : '').':00',
                'disponible' => filter_var($c['disponible'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        return $creneaux;
    }
}
