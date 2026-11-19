<?php

declare(strict_types=1);

namespace App\Services\Activation;

use Illuminate\Support\Carbon;

/**
 * Ce à quoi sert un jeton à usage unique (ADR-803-02) : un seul objet, une durée par usage.
 *
 * - Activation (#803) : le lien circule hors bande ; sept jours par défaut.
 * - Réinitialisation (#808) : le lien part par courriel, à la demande du titulaire ; il doit
 *   être COURT, soixante minutes par défaut.
 *
 * La consommation est la même : une seule fois, marquée en base.
 */
enum UsageDuJeton
{
    case Activation;
    case Reinitialisation;

    public function expiration(): Carbon
    {
        return match ($this) {
            self::Activation => now()->addDays(self::entierPositif('activation.validite_jours', 7)),
            self::Reinitialisation => now()->addMinutes(self::minutesDeReinitialisation()),
        };
    }

    /** La durée d'un lien de réinitialisation, dite dans le courriel. */
    public static function minutesDeReinitialisation(): int
    {
        return self::entierPositif('activation.reinitialisation_minutes', 60);
    }

    private static function entierPositif(string $cle, int $defaut): int
    {
        $valeur = config($cle, $defaut);

        return is_int($valeur) && $valeur > 0 ? $valeur : $defaut;
    }
}
