<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TypeDeDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Émettre un document de scolarité (#1662) : son `type`, EXIGÉ (`certificat_scolarite`, `attestation_frequentation`).
 *
 * `authorize()` rend `true` : le rôle est vérifié par la route (`gestion`), le droit par la porte du catalogue.
 */
final class EmettreUnDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['type' => ['required', 'string', Rule::enum(TypeDeDocument::class)]];
    }

    public function typeDeDocument(): TypeDeDocument
    {
        return TypeDeDocument::from($this->string('type')->toString());
    }
}
