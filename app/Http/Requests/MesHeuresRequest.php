<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /lms/mes-heures?mois=AAAA-MM` (#1682) : le mois de l'historique de l'enseignant. */
final class MesHeuresRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['mois' => ['required', 'date_format:Y-m']];
    }

    public function mois(): string
    {
        return $this->string('mois')->toString();
    }
}
