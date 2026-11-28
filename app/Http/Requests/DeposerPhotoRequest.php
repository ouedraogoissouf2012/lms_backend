<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates `POST /api/eleves/{eleve}/photo` (#1657, PR 2) : une image JPEG, PNG ou WebP, 5 Mo au plus. Le type est lu
 * sur le CONTENU (`mimes`), pas sur l'extension. Le serveur la ré-encode ensuite : ce qui ne se décode pas est refusé
 * par `PhotoDeLEleve`, sous la même clé `photo`.
 */
final class DeposerPhotoRequest extends FormRequest
{
    public const KILO_OCTETS_MAX = 5 * 1024;

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
            'photo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.self::KILO_OCTETS_MAX],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'photo.mimes' => 'La photo doit être une image JPEG, PNG ou WebP.',
            'photo.max' => 'La photo pèse au plus 5 Mo.',
        ];
    }
}
