<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /lms/enseignants/{id}/disponibilites/exceptions` (#1666) : une date ; un créneau facultatif (début ET fin, ou
 * aucun : la journée entière) ; disponible ou non ; un motif facultatif.
 */
final class ExceptionDeDisponibiliteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'debut' => ['nullable', 'date_format:H:i', 'required_with:fin'],
            'fin' => ['nullable', 'date_format:H:i', 'required_with:debut', 'after:debut'],
            'disponible' => ['required', 'boolean'],
            'motif' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @return array{date: string, debut: ?string, fin: ?string, disponible: bool, motif: ?string} */
    public function champs(): array
    {
        return [
            'date' => $this->string('date')->toString(),
            'debut' => $this->filled('debut') ? $this->string('debut')->toString().':00' : null,
            'fin' => $this->filled('fin') ? $this->string('fin')->toString().':00' : null,
            'disponible' => $this->boolean('disponible'),
            'motif' => $this->filled('motif') ? $this->string('motif')->trim()->toString() : null,
        ];
    }
}
