<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /lms/codes-emargement` (#1680) : la séance, la durée de validité (d'une heure à une journée) et le seuil de
 * retard facultatif. Aucune règle `exists` sur la séance : elle validerait l'existence GLOBALE ; la source cherche la
 * séance dans l'école et vérifie le droit (404, 403).
 */
final class GenererCodeEmargementRequest extends FormRequest
{
    public const DUREE_MIN = 60;

    public const DUREE_MAX = 1440;

    /** Au-delà de quatre heures, le « retard » ne décrit plus rien. */
    public const RETARD_MAX = 240;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'seance_id' => ['required', 'integer', 'min:1'],
            'duree_minutes' => ['required', 'integer', 'min:'.self::DUREE_MIN, 'max:'.self::DUREE_MAX],
            'retard_apres_minutes' => ['nullable', 'integer', 'min:0', 'max:'.self::RETARD_MAX],
        ];
    }

    public function seanceId(): int
    {
        return $this->integer('seance_id');
    }

    public function dureeMinutes(): int
    {
        return $this->integer('duree_minutes');
    }

    public function retardApresMinutes(): ?int
    {
        return $this->filled('retard_apres_minutes') ? $this->integer('retard_apres_minutes') : null;
    }
}
