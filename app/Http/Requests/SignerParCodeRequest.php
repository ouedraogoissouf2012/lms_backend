<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Enrollment\ClasseEnrolmentCode;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /lms/emargement` (#1680) : le code saisi par l'élève, sous les règles de saisie du code d'inscription (la
 * casse et les espaces sont normalisés par la source, jamais par le moteur).
 */
final class SignerParCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['code' => ClasseEnrolmentCode::REGLES_DE_SAISIE];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}
