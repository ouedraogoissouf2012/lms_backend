<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Enrollment\ClasseEnrolmentCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rejoindre une classe avec son code, en étant déjà connecté (#885).
 *
 * `authorize()` rend `true` : le rôle est vérifié par `role:etudiant` sur la
 * route, et le droit sur la classe, c'est le code lui-même — résolu par le
 * service dans l'établissement du compte.
 *
 * Les bornes du code sont celles de la porte anonyme, par construction : les
 * deux lisent `ClasseEnrolmentCode::REGLES_DE_SAISIE`.
 */
final class RejoindreParCodeRequest extends FormRequest
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
        return [
            'code' => ClasseEnrolmentCode::REGLES_DE_SAISIE,
        ];
    }

    public function code(): string
    {
        return (string) $this->string('code');
    }
}
