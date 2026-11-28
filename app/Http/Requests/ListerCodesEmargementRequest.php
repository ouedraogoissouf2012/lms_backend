<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `GET /lms/codes-emargement?seance_id=` (#1680) : les codes d'UNE séance. */
final class ListerCodesEmargementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['seance_id' => ['required', 'integer', 'min:1']];
    }

    public function seanceId(): int
    {
        return $this->integer('seance_id');
    }
}
