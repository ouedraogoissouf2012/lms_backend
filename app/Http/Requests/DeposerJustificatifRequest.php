<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /lms/justificatifs` (#1681), multipart : le motif, les séances nommées OU une plage de dates, la pièce
 * facultative (pdf, jpg, png ; 10 Mo au plus). Aucune règle `exists` sur les séances : elle validerait l'existence
 * GLOBALE ; la source cherche chaque séance dans les classes de l'élève (404 sinon).
 */
final class DeposerJustificatifRequest extends FormRequest
{
    public const MOTIF_MAX = 1000;

    public const SEANCES_MAX = 100;

    /** En kilo-octets, l'unité de la règle `max` d'un fichier. */
    public const PIECE_MAX_KO = 10240;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'motif' => ['required', 'string', 'max:'.self::MOTIF_MAX],
            'seance_ids' => ['required_without:du', 'prohibits:du,au', 'array', 'min:1', 'max:'.self::SEANCES_MAX],
            'seance_ids.*' => ['integer', 'min:1', 'distinct'],
            'du' => ['required_without:seance_ids', 'nullable', 'date_format:Y-m-d'],
            'au' => ['required_with:du', 'nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
            'piece' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.self::PIECE_MAX_KO],
        ];
    }

    public function motif(): string
    {
        return $this->string('motif')->trim()->toString();
    }

    /** @return ?list<int> */
    public function seanceIds(): ?array
    {
        if (! $this->filled('seance_ids')) {
            return null;
        }
        $ids = $this->validated('seance_ids');

        return is_array($ids) ? array_values(array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $ids)) : null;
    }

    public function du(): ?string
    {
        return $this->filled('du') ? $this->string('du')->toString() : null;
    }

    public function au(): ?string
    {
        return $this->filled('au') ? $this->string('au')->toString() : null;
    }
}
