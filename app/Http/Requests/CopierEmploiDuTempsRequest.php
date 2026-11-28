<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /lms/emplois-du-temps/{id}/copier` (#1669) : la classe cible, sa plage, un titre facultatif. */
final class CopierEmploiDuTempsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'classe_id' => ['required', 'integer', 'min:1'],
            'du' => ['required', 'date_format:Y-m-d'],
            'au' => ['required', 'date_format:Y-m-d', 'after_or_equal:du'],
            'titre' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function classeId(): int
    {
        return $this->integer('classe_id');
    }

    public function du(): string
    {
        return $this->string('du')->toString();
    }

    public function au(): string
    {
        return $this->string('au')->toString();
    }

    public function titre(): ?string
    {
        return $this->filled('titre') ? $this->string('titre')->trim()->toString() : null;
    }
}
