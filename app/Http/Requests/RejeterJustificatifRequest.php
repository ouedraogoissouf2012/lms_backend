<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /lms/justificatifs/{id}/rejeter` (#1681) : le motif du rejet est obligatoire. */
final class RejeterJustificatifRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['motif' => ['required', 'string', 'max:'.DeposerJustificatifRequest::MOTIF_MAX]];
    }

    public function motif(): string
    {
        return $this->string('motif')->trim()->toString();
    }
}
